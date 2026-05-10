<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\AccountPayable\Status as AccountPayableStatus;
use App\Enum\FiscalDocument\PurchaseReturnSettlementMode;
use App\Enum\PurchaseReturnCredit\Status as PurchaseReturnCreditStatus;
use App\Models\AccountPayable;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItemOrigin;
use App\Models\PurchaseReturnCredit;
use App\Services\AccountPayable\AccountPayableService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessPurchaseReturnFinancialImpactAction
{
    use HandlesActionResponse;

    public function __construct(
        private readonly AccountPayableService $accountPayableService = new AccountPayableService(),
    ) {}

    /**
     * @return array{credits:int,replacement_payables:int,updated_payables:int,warnings:string[]}
     */
    public function execute(FiscalDocument $returnDocument, int $userId): array
    {
        $this->resetResponse();

        $result = [
            'credits' => 0,
            'replacement_payables' => 0,
            'updated_payables' => 0,
            'warnings' => [],
        ];

        if (! $returnDocument->isPurchaseReturn()) {
            $this->setSuccess();
            return $result;
        }

        if ($returnDocument->hasProcessedReturnFinancial()) {
            $this->setSuccess();
            return $result;
        }

        if (! $returnDocument->hasReturnFinancialConfiguration()) {
            $this->setSuccess();
            return $result;
        }

        $returnDocument->loadMissing('items');
        $originDocument = $this->resolveOriginDocument($returnDocument);

        if (! $originDocument) {
            $this->setError('Nota fiscal de origem da devolução não encontrada.');
            return $result;
        }

        $mode = PurchaseReturnSettlementMode::from((string) data_get($returnDocument->return_financial_data, 'mode'));
        $returnAmount = (float) $returnDocument->items->sum(fn ($item) => (float) $item->total_price);

        try {
            DB::transaction(function () use ($returnDocument, $originDocument, $mode, $returnAmount, $userId, &$result): void {
                $payables = AccountPayable::query()
                    ->where('fiscal_document_id', $originDocument->id)
                    ->orderBy('due_date')
                    ->get();

                $result = match ($mode) {
                    PurchaseReturnSettlementMode::CANCEL_PAYABLE => $this->cancelOriginPayables($returnDocument, $payables),
                    PurchaseReturnSettlementMode::SUPPLIER_CREDIT => $this->generateSupplierCredit($returnDocument, $originDocument, $payables, $returnAmount, $userId),
                    PurchaseReturnSettlementMode::REPLACE_PAYABLE => $this->replaceOriginPayables($returnDocument, $originDocument, $payables, $returnAmount, $userId),
                };

                $financialData = is_array($returnDocument->return_financial_data)
                    ? $returnDocument->return_financial_data
                    : [];

                $financialData['processed_result'] = [
                    'credits' => $result['credits'],
                    'replacement_payables' => $result['replacement_payables'],
                    'updated_payables' => $result['updated_payables'],
                    'warnings' => $result['warnings'],
                    'processed_at' => now()->toAtomString(),
                ];

                $returnDocument->forceFill([
                    'return_financial_data' => $financialData,
                    'return_financial_processed_at' => now(),
                    'return_financial_processed_by' => $userId,
                ])->save();
            });
        } catch (\Throwable $e) {
            $this->setError('Erro ao processar impacto financeiro da devolução: ' . $e->getMessage());

            Log::error('ProcessPurchaseReturnFinancialImpactAction: excecao', [
                'fiscal_document_id' => $returnDocument->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $result;
        }

        $this->setSuccess('Impacto financeiro da devolução processado com sucesso.');

        return $result;
    }

    private function resolveOriginDocument(FiscalDocument $returnDocument): ?FiscalDocument
    {
        $originId = (int) data_get($returnDocument->tax_data, 'purchase_return_origin.fiscal_document_id', 0);

        if ($originId > 0) {
            return FiscalDocument::query()->find($originId);
        }

        $originLink = FiscalDocumentItemOrigin::query()
            ->where('return_fiscal_document_id', $returnDocument->id)
            ->first();

        return $originLink?->originDocument;
    }

    private function cancelOriginPayables(FiscalDocument $returnDocument, $payables): array
    {
        $updated = 0;
        $warnings = [];

        foreach ($payables as $payable) {
            if ($payable->status === AccountPayableStatus::CANCELLED) {
                continue;
            }

            if ($payable->status === AccountPayableStatus::PAID) {
                $warnings[] = "Conta a pagar #{$payable->id} já está paga e foi mantida.";
                continue;
            }

            $payable->update([
                'status' => AccountPayableStatus::CANCELLED->value,
                'type' => 'purchase_return_cancelled',
                'description' => $this->appendDescription(
                    $payable->description,
                    "Cancelada por devolução NF #{$returnDocument->document_number}"
                ),
            ]);

            $updated++;
        }

        return [
            'credits' => 0,
            'replacement_payables' => 0,
            'updated_payables' => $updated,
            'warnings' => $warnings,
        ];
    }

    private function generateSupplierCredit(FiscalDocument $returnDocument, FiscalDocument $originDocument, $payables, float $returnAmount, int $userId): array
    {
        PurchaseReturnCredit::query()->firstOrCreate(
            ['return_fiscal_document_id' => $returnDocument->id],
            [
                'company_id' => $returnDocument->company_id,
                'partner_id' => $returnDocument->customer_id,
                'origin_fiscal_document_id' => $originDocument->id,
                'amount' => $returnAmount,
                'used_amount' => 0,
                'status' => PurchaseReturnCreditStatus::OPEN->value,
                'notes' => data_get($returnDocument->return_financial_data, 'notes'),
                'metadata' => [
                    'mode' => PurchaseReturnSettlementMode::SUPPLIER_CREDIT->value,
                    'origin_account_payable_ids' => $payables->pluck('id')->values()->all(),
                ],
                'created_by' => $userId,
                'updated_by' => $userId,
            ]
        );

        return [
            'credits' => 1,
            'replacement_payables' => 0,
            'updated_payables' => 0,
            'warnings' => [],
        ];
    }

    private function replaceOriginPayables(FiscalDocument $returnDocument, FiscalDocument $originDocument, $payables, float $returnAmount, int $userId): array
    {
        $updated = 0;
        $warnings = [];
        $openBalance = 0.0;

        foreach ($payables as $payable) {
            $openBalance += $this->resolveOpenAmount($payable);

            if (in_array($payable->status, [AccountPayableStatus::CANCELLED, AccountPayableStatus::PAID], true)) {
                if ($payable->status === AccountPayableStatus::PAID) {
                    $warnings[] = "Conta a pagar #{$payable->id} já está paga e foi mantida.";
                }

                continue;
            }

            $payable->update([
                'status' => AccountPayableStatus::CANCELLED->value,
                'type' => 'purchase_return_replaced',
                'description' => $this->appendDescription(
                    $payable->description,
                    "Substituída por devolução NF #{$returnDocument->document_number}"
                ),
            ]);

            $updated++;
        }

        $replacementAmount = round(max($openBalance - $returnAmount, 0), 4);

        if ($returnAmount > $openBalance + 0.0001) {
            $warnings[] = 'Valor da devolução maior que o saldo em aberto identificado no contas a pagar original.';
        }

        if ($replacementAmount <= 0.0001) {
            return [
                'credits' => 0,
                'replacement_payables' => 0,
                'updated_payables' => $updated,
                'warnings' => $warnings,
            ];
        }

        $firstPayable = $payables->first();
        $replacementDueDate = (string) data_get($returnDocument->return_financial_data, 'replacement_due_date', now()->toDateString());

        $replacement = $this->accountPayableService->create([
            'supplier_id' => $returnDocument->customer_id,
            'company_id' => $returnDocument->company_id,
            'fiscal_document_id' => $returnDocument->id,
            'sequence_number' => '1',
            'status' => AccountPayableStatus::PENDING->value,
            'payment_method' => $firstPayable?->payment_method,
            'due_date' => $replacementDueDate,
            'due_amount' => $replacementAmount,
            'description' => data_get($returnDocument->return_financial_data, 'replacement_description')
                ?: "Reemissão por devolução da NF #{$originDocument->document_number}",
            'document_number' => $returnDocument->document_number,
            'type' => 'purchase_return_replacement',
        ], $userId);

        if (! $replacement || $this->accountPayableService->hasError()) {
            throw new \RuntimeException($this->accountPayableService->getMessageUser());
        }

        return [
            'credits' => 0,
            'replacement_payables' => 1,
            'updated_payables' => $updated,
            'warnings' => $warnings,
        ];
    }

    private function resolveOpenAmount(AccountPayable $payable): float
    {
        return match ($payable->status) {
            AccountPayableStatus::PAID, AccountPayableStatus::CANCELLED => 0.0,
            AccountPayableStatus::PARTIALLY_PAID => max((float) $payable->due_amount - (float) ($payable->paid_amount ?? 0), 0),
            default => (float) $payable->due_amount,
        };
    }

    private function appendDescription(?string $current, string $suffix): string
    {
        $current = trim((string) $current);

        if ($current === '') {
            return $suffix;
        }

        return $current . ' | ' . $suffix;
    }
}
