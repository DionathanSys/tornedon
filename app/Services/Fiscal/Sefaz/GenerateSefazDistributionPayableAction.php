<?php

namespace App\Services\Fiscal\Sefaz;

use App\Enum\AccountPayable\Status as AccountPayableStatus;
use App\Enum\Audit\AuditSource;
use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Models\AccountPayable;
use App\Models\SefazDistributionDocument;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

class GenerateSefazDistributionPayableAction
{
    public function __construct(
        private readonly AccountPayableService $accountPayableService,
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /**
     * @param  array{payment_method:string,payment_condition:string,due_date:string,description?:?string}  $paymentData
     */
    public function execute(SefazDistributionDocument $distributionDocument, array $paymentData, int $userId): AccountPayable
    {
        if (! $distributionDocument->full_xml_available) {
            throw new \RuntimeException('O DF-e ainda não possui XML completo disponível.');
        }

        if ($distributionDocument->import_status === ImportStatus::IGNORED) {
            throw new \RuntimeException('Não é possível gerar contas a pagar para um DF-e ignorado.');
        }

        if (blank($distributionDocument->partner_id)) {
            throw new \RuntimeException('Vincule um fornecedor antes de gerar contas a pagar.');
        }

        return DB::transaction(function () use ($distributionDocument, $paymentData, $userId): AccountPayable {
            $locked = SefazDistributionDocument::query()
                ->whereKey($distributionDocument->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->import_status === ImportStatus::IGNORED || ! $locked->full_xml_available || ! $locked->partner_id) {
                throw new \RuntimeException('O DF-e deve possuir XML completo, fornecedor e não estar ignorado.');
            }
            $links = app(SefazDistributionFinancialLinkService::class);
            if ($existing = $links->synchronize($locked, actorId: $userId)) {
                return $existing;
            }
            $locked->refresh();
            $plan = app(SefazDistributionPayablePlan::class)->resolve($locked, $paymentData);
            $amount = $plan['amount'];
            $description = filled($paymentData['description'] ?? null)
                ? (string) $paymentData['description']
                : "DF-e NF #{$locked->document_number}";

            $payload = [
                'supplier_id' => $locked->partner_id,
                'company_id' => $locked->company_id,
                'fiscal_document_id' => $locked->fiscal_document_id,
                'sequence_number' => '01',
                'status' => AccountPayableStatus::PENDING->value,
                'payment_method' => $paymentData['payment_method'],
                'due_date' => $plan['installments'][0]['due_date'] ?? now()->toDateString(),
                'due_amount' => $amount,
                'description' => $description,
                'document_number' => $locked->document_key,
                'note_number' => $locked->document_number,
                'installments' => $plan['installments'],
                'installment_count' => count($plan['installments']),
                'financial_category_id' => $paymentData['financial_category_id'] ?? null,
                'cost_center_id' => $paymentData['cost_center_id'] ?? null,
                'result_center_id' => $paymentData['result_center_id'] ?? null,
                'bank_slip_number' => $paymentData['bank_slip_number'] ?? null,
                'is_effective' => (bool) ($paymentData['is_effective'] ?? true),
                'auto_register_payment_on_due_date' => (bool) ($paymentData['auto_register_payment_on_due_date'] ?? false),
                'auto_payment_financial_account_id' => $paymentData['auto_payment_financial_account_id'] ?? null,
            ];

            $payable = $this->accountPayableService->create($payload, $userId);

            if ($this->accountPayableService->hasError() || ! $payable) {
                throw new \RuntimeException('Erro ao criar conta a pagar: '.$this->accountPayableService->getMessageUser());
            }

            $before = $this->auditRecorder->snapshot($locked);
            $locked->forceFill([
                'account_payable_id' => $payable->id,
                'last_action' => 'account_payable_generated',
                'last_action_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();
            $links->synchronize($locked, $payable, actorId: $userId);

            $this->auditRecorder->recordModelEvent(
                $locked->fresh(),
                'sefaz_distribution.account_payable_generated',
                'Conta a pagar gerada a partir do DF-e detectado',
                $before,
                $this->auditRecorder->snapshot($locked->fresh()),
                $userId,
                AuditSource::WEB,
                [
                    'account_payable_id' => $payable->id,
                    'amount' => $amount,
                    'fiscal_amount' => $plan['source']['fiscal_amount'],
                    'amount_difference_reason' => $paymentData['amount_difference_reason'] ?? null,
                    'installments_source' => $paymentData['installments_source'] ?? 'condition',
                ],
            );

            return $payable;
        });
    }
}
