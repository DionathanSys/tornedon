<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\AccountPayable\Status as AccountPayableStatus;
use App\Enum\FiscalDocument\PurchaseReturnSettlementMode;
use App\Enum\PurchaseReturnCredit\Status as PurchaseReturnCreditStatus;
use App\Models\AccountPayable;
use App\Models\FiscalDocument;
use App\Models\PurchaseReturnCredit;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReversePurchaseReturnFinancialImpactAction
{
    use HandlesActionResponse;

    /**
     * @return array{restored_payables:int,canceled_replacement_payables:int,canceled_credits:int,warnings:string[],errors:string[]}
     */
    public function execute(FiscalDocument $returnDocument, int $userId): array
    {
        $this->resetResponse();

        $result = [
            'restored_payables' => 0,
            'canceled_replacement_payables' => 0,
            'canceled_credits' => 0,
            'warnings' => [],
            'errors' => [],
        ];

        if (! $returnDocument->isPurchaseReturn() || ! $returnDocument->hasProcessedReturnFinancial()) {
            $this->setSuccess();

            return $result;
        }

        if ($returnDocument->return_financial_reversed_at !== null) {
            $this->setSuccess();

            return $result;
        }

        $mode = PurchaseReturnSettlementMode::tryFrom((string) data_get($returnDocument->return_financial_data, 'mode'));

        if ($mode === null || $mode === PurchaseReturnSettlementMode::NONE) {
            $this->markReversed($returnDocument, $userId, $result);
            $this->setSuccess();

            return $result;
        }

        try {
            DB::transaction(function () use ($returnDocument, $userId, $mode, &$result): void {
                if ($mode === PurchaseReturnSettlementMode::SUPPLIER_CREDIT) {
                    $this->reverseSupplierCredit($returnDocument, $result);
                }

                if (in_array($mode, [
                    PurchaseReturnSettlementMode::CANCEL_PAYABLE,
                    PurchaseReturnSettlementMode::REPLACE_PAYABLE,
                ], true)) {
                    $this->restoreOriginPayables($returnDocument, $result);
                }

                if ($mode === PurchaseReturnSettlementMode::REPLACE_PAYABLE) {
                    $this->cancelReplacementPayables($returnDocument, $result);
                }

                if ($result['warnings'] === [] && $result['errors'] === []) {
                    $this->markReversed($returnDocument, $userId, $result);
                }
            });
        } catch (\Throwable $e) {
            $result['errors'][] = 'Erro ao reverter impacto financeiro: '.$e->getMessage();
            $this->setError($result['errors'][0]);

            Log::error('ReversePurchaseReturnFinancialImpactAction: exceção', [
                'fiscal_document_id' => $returnDocument->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $result;
        }

        if ($result['warnings'] !== [] || $result['errors'] !== []) {
            $this->setError('A reversão financeira da devolução exige revisão.', [
                'warnings' => $result['warnings'],
                'errors' => $result['errors'],
            ]);

            return $result;
        }

        $this->setSuccess();

        return $result;
    }

    private function reverseSupplierCredit(FiscalDocument $returnDocument, array &$result): void
    {
        $credit = PurchaseReturnCredit::query()
            ->where('return_fiscal_document_id', $returnDocument->id)
            ->lockForUpdate()
            ->first();

        if (! $credit || $credit->status === PurchaseReturnCreditStatus::CANCELLED) {
            return;
        }

        if ((float) $credit->used_amount > 0) {
            $result['warnings'][] = 'O crédito do fornecedor já foi utilizado e não foi cancelado automaticamente.';

            return;
        }

        $credit->update([
            'status' => PurchaseReturnCreditStatus::CANCELLED->value,
            'notes' => trim((string) $credit->notes.' | Cancelado pela NF-e de devolução cancelada.'),
        ]);
        $result['canceled_credits']++;
    }

    private function restoreOriginPayables(FiscalDocument $returnDocument, array &$result): void
    {
        $snapshots = data_get($returnDocument->return_financial_data, 'origin_payables_snapshot', []);

        if (! is_array($snapshots)) {
            return;
        }

        foreach ($snapshots as $snapshot) {
            $payable = AccountPayable::query()
                ->lockForUpdate()
                ->find((int) ($snapshot['id'] ?? 0));

            if (! $payable) {
                continue;
            }

            if ($payable->status === AccountPayableStatus::PAID || (float) ($payable->paid_amount ?? 0) > 0) {
                $result['warnings'][] = "A conta a pagar #{$payable->id} recebeu pagamento após o processamento da devolução e exige revisão.";

                continue;
            }

            $payable->update([
                'status' => $snapshot['status'] ?? AccountPayableStatus::PENDING->value,
                'type' => $snapshot['type'] ?? null,
                'description' => $snapshot['description'] ?? $payable->description,
                'due_amount' => (float) ($snapshot['due_amount'] ?? $payable->due_amount),
                'paid_amount' => (float) ($snapshot['paid_amount'] ?? 0),
                'paid' => (bool) ($snapshot['paid'] ?? false),
                'paid_date' => $snapshot['paid_date'] ?? null,
            ]);
            $result['restored_payables']++;
        }
    }

    private function cancelReplacementPayables(FiscalDocument $returnDocument, array &$result): void
    {
        $replacementPayables = AccountPayable::query()
            ->where('fiscal_document_id', $returnDocument->id)
            ->where('type', 'purchase_return_replacement')
            ->lockForUpdate()
            ->get();

        foreach ($replacementPayables as $payable) {
            if ($payable->status === AccountPayableStatus::PAID || (float) ($payable->paid_amount ?? 0) > 0) {
                $result['warnings'][] = "O boleto substituto #{$payable->id} já recebeu pagamento e exige revisão.";

                continue;
            }

            if ($payable->status !== AccountPayableStatus::CANCELLED) {
                $payable->update([
                    'status' => AccountPayableStatus::CANCELLED->value,
                    'description' => trim((string) $payable->description.' | Cancelado pela NF-e de devolução cancelada.'),
                ]);
                $result['canceled_replacement_payables']++;
            }
        }
    }

    private function markReversed(FiscalDocument $returnDocument, int $userId, array $result): void
    {
        $data = is_array($returnDocument->return_financial_data)
            ? $returnDocument->return_financial_data
            : [];

        $data['reversal_result'] = [
            'restored_payables' => $result['restored_payables'],
            'canceled_replacement_payables' => $result['canceled_replacement_payables'],
            'canceled_credits' => $result['canceled_credits'],
            'reversed_at' => now()->toAtomString(),
        ];

        $returnDocument->forceFill([
            'return_financial_data' => $data,
            'return_financial_reversed_at' => now(),
            'return_financial_reversed_by' => $userId,
        ])->save();
    }
}
