<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\AccountPayable\Status as AccountPayableStatus;
use App\Enum\Payment\Condition;
use App\Models\FiscalDocument;
use App\Models\SefazDistributionDocument;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\Fiscal\Sefaz\SefazDistributionFinancialLinkService;
use App\Support\Financial\FiscalEntryAmount;
use App\Support\Financial\InstallmentSchedule;
use App\Support\Financial\PaymentTerms;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GenerateFiscalEntryPayableAction
{
    public function __construct(
        private readonly AccountPayableService $accountPayableService = new AccountPayableService,
    ) {}

    /**
     * @param  array{
     *     payment_method: string,
     *     payment_condition?: string,
     *     installment_count?: int,
     *     installment_due_mode?: string,
     *     installment_fixed_day?: int,
     *     installment_interval_days?: int,
     *     due_date: string,
     *     description: ?string,
     * } $paymentData
     * @return array{payables: int, errors: string[]}
     */
    public function execute(FiscalDocument $document, array $paymentData, int $userId): array
    {
        return DB::transaction(function () use ($document, $paymentData, $userId): array {
            $dfe = filled($document->document_key) ? SefazDistributionDocument::query()
                ->where('company_id', $document->company_id)->where('document_key', $document->document_key)
                ->lockForUpdate()->first() : null;
            if ($dfe && app(SefazDistributionFinancialLinkService::class)->synchronize($dfe, note: $document, actorId: $userId)) {
                return ['payables' => 0, 'errors' => []];
            }
            $document = FiscalDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->accountPayables()->exists()) {
                return ['payables' => 0, 'errors' => []];
            }
            $result = $this->createPayable($document, $paymentData, $userId);
            if ($dfe && $result['payables'] > 0) {
                app(SefazDistributionFinancialLinkService::class)->synchronize($dfe, note: $document, actorId: $userId);
            }

            return $result;
        });
    }

    private function createPayable(FiscalDocument $document, array $paymentData, int $userId): array
    {
        if ($document->isOperationalHistory()) {
            return ['payables' => 0, 'errors' => []];
        }

        $document->loadMissing('items');

        $totalAmount = FiscalEntryAmount::fromDocument($document);
        $description = filled($paymentData['description'] ?? null) ? $paymentData['description'] : "NF #{$document->document_number}";
        $result = [
            'payables' => 0,
            'errors' => [],
        ];

        $payload = [
            'supplier_id' => $document->customer_id,
            'company_id' => $document->company_id,
            'fiscal_document_id' => $document->id,
            'sequence_number' => '01',
            'status' => AccountPayableStatus::PENDING->value,
            'payment_method' => $paymentData['payment_method'],
            'due_date' => $paymentData['due_date'] ?? now()->toDateString(),
            'due_amount' => $totalAmount,
            'description' => $description,
            'document_number' => $document->document_number,
            'note_number' => $document->document_number,
            'bank_slip_number' => $paymentData['bank_slip_number'] ?? null,
            'financial_category_id' => $paymentData['financial_category_id'] ?? null,
            'cost_center_id' => $paymentData['cost_center_id'] ?? null,
            'result_center_id' => $paymentData['result_center_id'] ?? null,
            'is_effective' => (bool) ($paymentData['is_effective'] ?? false),
            'auto_register_payment_on_due_date' => (bool) ($paymentData['auto_register_payment_on_due_date'] ?? false),
            'auto_payment_financial_account_id' => $paymentData['auto_payment_financial_account_id'] ?? null,
        ];

        if (array_key_exists('installment_count', $paymentData)) {
            $payload['installment_count'] = $paymentData['installment_count'];
            if ((int) $paymentData['installment_count'] > 1) {
                $payload['installment_due_mode'] = $paymentData['installment_due_mode'] ?? Condition::DAYS_30->value;
                $payload['installment_fixed_day'] = $paymentData['installment_fixed_day'] ?? null;
                $payload['installment_interval_days'] = $paymentData['installment_interval_days'] ?? null;
            }
        } else {
            // Compatibilidade com chamadas que ainda informam a condição de pagamento.
            $condition = Condition::from($paymentData['payment_condition']);
            $payload['installment_count'] = PaymentTerms::count($condition, $paymentData['payment_condition_details'] ?? null);
            if ($condition === Condition::CUSTOM) {
                $payload['due_date'] = PaymentTerms::firstDueDate($condition, Carbon::parse($document->issued_at ?? now()), $paymentData['payment_condition_details'])->toDateString();
                $payload['installment_due_mode'] = InstallmentSchedule::CUSTOM_INTERVAL_DAYS;
                $payload['installment_interval_days'] = PaymentTerms::interval($condition, $paymentData['payment_condition_details']);
            } elseif ($condition->isTerm() && $payload['installment_count'] > 1) {
                $payload['installment_due_mode'] = $condition->value;
            }
        }

        $payable = $this->accountPayableService->create($payload, $userId);

        if ($this->accountPayableService->hasError() || ! $payable) {
            $result['errors'][] = 'Erro ao criar conta a pagar: '
                .$this->accountPayableService->getMessageUser();

            return $result;
        }

        $result['payables'] = 1;

        return $result;
    }
}
