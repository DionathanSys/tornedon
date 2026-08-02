<?php

namespace App\Services\Invoice\Actions;

use App\Enum\Invoice\Status as InvoiceStatus;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Models\CardPaymentProfile;
use App\Models\CompanyPreference;
use App\Models\Invoice;
use App\Services\AccountReceivable\AccountReceivableService;
use App\Services\Audit\AuditRecorder;
use App\Support\Financial\InstallmentSchedule;
use App\Traits\HandlesActionResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateInvoiceAccountReceivablesAction
{
    use HandlesActionResponse;

    public function __construct(
        private Invoice $invoice,
        private int $userId,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>|null
     */
    public function execute(array $data): ?array
    {
        $this->invoice->loadMissing('accountReceivables');

        if (! $this->validateCanGenerate()) {
            return null;
        }

        $paymentMethod = PaymentMethod::from((string) $data['payment_method']);
        $paymentCondition = $this->resolvePaymentCondition($paymentMethod, $data);

        if ($paymentCondition === false) {
            return null;
        }

        $installments = $this->buildInstallments($paymentMethod, $paymentCondition, $data);

        if ($installments === []) {
            return null;
        }

        $audit = app(AuditRecorder::class);
        $before = $audit->snapshot($this->invoice);

        $this->invoice->update([
            'payment_method' => $paymentMethod->value,
            'payment_condition' => $paymentCondition?->value,
            'financial_category_id' => $this->resolveFinancialCategoryId($data),
            'updated_by' => $this->userId,
        ]);

        $service = app(AccountReceivableService::class);
        $accountReceivable = $service->create([
            'customer_id' => $this->invoice->customer_id,
            'company_id' => $this->invoice->company_id,
            'invoice_id' => $this->invoice->id,
            'fiscal_document_id' => null,
            'due_date' => $installments[0]['due_date'],
            'paid_date' => null,
            'due_amount' => round((float) $this->invoice->netValue, 2),
            'paid_amount' => 0,
            'document_number' => Str::padLeft($this->invoice->invoice_number, 5, '0'),
            'description' => sprintf(
                'Fatura: %s',
                Str::padLeft($this->invoice->invoice_number, 5, '0')
            ),
            'paid' => false,
            'payment_method' => $paymentMethod->value,
            'card_payment_profile_id' => $paymentMethod === PaymentMethod::CREDIT_CARD
                ? (int) ($data['card_payment_profile_id'] ?? 0)
                : null,
            'payment_date' => $paymentMethod === PaymentMethod::CREDIT_CARD
                ? (string) ($data['payment_date'] ?? $this->invoice->invoice_date?->toDateString() ?? now()->toDateString())
                : null,
            'financial_category_id' => $this->invoice->financial_category_id,
            'installment_count' => count($installments),
            'installment_due_mode' => InstallmentSchedule::CUSTOM_INTERVAL_DAYS,
            'installment_interval_days' => 30,
        ], $this->userId);

        if ($service->hasError() || $accountReceivable === null) {
            $this->setError(
                $service->getMessage(),
                $service->getErrors(),
                $service->getStatus(),
                $service->getErrorCode(),
            );

            return null;
        }

        $this->invoice->refresh();

        $audit->recordModelEvent(
            $this->invoice,
            'invoice.account_receivables_generated',
            "Contas a receber geradas para a fatura #{$this->invoice->invoice_number}",
            $before,
            $audit->snapshot($this->invoice),
            $this->userId,
            null,
            [
                'account_receivable_id' => $accountReceivable->id,
                'payment_method' => $paymentMethod->value,
                'payment_condition' => $paymentCondition?->value,
            ],
        );

        Log::info('Contas a receber geradas manualmente para a fatura', [
            'metodo' => __METHOD__.'@'.__LINE__,
            'invoice_id' => $this->invoice->id,
            'account_receivable_id' => $accountReceivable->id,
            'user_id' => $this->userId,
        ]);

        $this->setSuccess('Contas a receber geradas com sucesso.');

        return [$accountReceivable];
    }

    private function validateCanGenerate(): bool
    {
        if ($this->invoice->canceled || $this->invoice->status === InvoiceStatus::CANCELLED) {
            $this->setError('Nao e possivel gerar contas a receber para uma fatura cancelada.');

            return false;
        }

        if ($this->invoice->status !== InvoiceStatus::CONFIRMED || ! $this->invoice->confirmed) {
            $this->setError('As contas a receber isoladas so podem ser geradas para faturas confirmadas.');

            return false;
        }

        if ($this->invoice->accountReceivables->isNotEmpty()) {
            $this->setError('Esta fatura ja possui contas a receber vinculadas.');

            return false;
        }

        if (round((float) $this->invoice->netValue, 2) <= 0) {
            $this->setError('Valor liquido da fatura invalido para gerar contas a receber.');

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, int|float|string>>
     */
    private function buildInstallments(PaymentMethod $paymentMethod, ?PaymentCondition $condition, array $data): array
    {
        $netValue = round((float) $this->invoice->netValue, 2);
        if ($paymentMethod !== PaymentMethod::CREDIT_CARD && $condition === null) {
            $this->setError('Condicao de pagamento obrigatoria para gerar contas a receber da fatura.');

            return [];
        }

        $totalCents = (int) round($netValue * 100);
        $installmentsCount = max(1, $condition?->installments() ?: 1);
        $baseCents = intdiv($totalCents, $installmentsCount);
        $remainder = $totalCents - ($baseCents * $installmentsCount);
        $baseDate = Carbon::parse($this->invoice->invoice_date ?? now()->toDateString());
        $cardPaymentDate = $paymentMethod === PaymentMethod::CREDIT_CARD
            ? Carbon::parse((string) ($data['payment_date'] ?? $this->invoice->invoice_date?->toDateString() ?? now()->toDateString()))
            : null;
        $profile = $paymentMethod === PaymentMethod::CREDIT_CARD
            ? $this->resolveCardProfile((int) ($data['card_payment_profile_id'] ?? 0))
            : null;
        $installments = [];

        for ($i = 1; $i <= $installmentsCount; $i++) {
            $amountCents = $baseCents + ($i === $installmentsCount ? $remainder : 0);

            $installments[] = [
                'sequence_number' => str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'due_date' => $this->resolveDueDate($paymentMethod, $condition, $baseDate, $i, $cardPaymentDate, $profile)->toDateString(),
                'due_amount' => round($amountCents / 100, 2),
                'installment_number' => $i,
                'installments_count' => $installmentsCount,
            ];
        }

        return $installments;
    }

    private function resolveDueDate(
        PaymentMethod $paymentMethod,
        ?PaymentCondition $condition,
        Carbon $baseDate,
        int $installmentNumber,
        ?Carbon $cardPaymentDate,
        ?CardPaymentProfile $cardPaymentProfile,
    ): Carbon {
        if ($paymentMethod === PaymentMethod::CREDIT_CARD && $cardPaymentDate && $cardPaymentProfile) {
            $firstDueDate = $cardPaymentDate->copy()->addDays((int) $cardPaymentProfile->settlement_days);

            return $installmentNumber === 1
                ? $firstDueDate
                : $firstDueDate->copy()->addDays(30 * ($installmentNumber - 1));
        }

        if ($condition === null) {
            return $baseDate->copy();
        }

        if ($condition->isCash() || $condition === PaymentCondition::CUSTOM) {
            return $baseDate->copy();
        }

        if ($condition->installments() > 1) {
            $daysStep = max($condition->days(), 30);

            return $baseDate->copy()->addDays($daysStep * $installmentNumber);
        }

        if ($condition->isTerm()) {
            return $baseDate->copy()->addDays($condition->days());
        }

        return $baseDate->copy();
    }

    private function resolveCardProfile(int $profileId): ?CardPaymentProfile
    {
        if ($profileId <= 0) {
            $this->setError('Selecione o perfil de recebimento para gerar contas a receber em cartao de credito.');

            return null;
        }

        $profile = CardPaymentProfile::query()
            ->where('company_id', $this->invoice->company_id)
            ->where('active', true)
            ->find($profileId);

        if (! $profile) {
            $this->setError('Perfil de cartao invalido para a empresa da fatura.');

            return null;
        }

        return $profile;
    }

    private function resolvePaymentCondition(PaymentMethod $paymentMethod, array $data): PaymentCondition|false|null
    {
        $rawCondition = $data['payment_condition'] ?? null;

        if (blank($rawCondition)) {
            if ($paymentMethod === PaymentMethod::CREDIT_CARD) {
                return null;
            }

            $this->setError('Condicao de pagamento obrigatoria para gerar contas a receber da fatura.');

            return false;
        }

        return PaymentCondition::from((string) $rawCondition);
    }

    private function resolveFinancialCategoryId(array $data): ?int
    {
        $categoryId = $data['financial_category_id']
            ?? $this->invoice->financial_category_id
            ?? CompanyPreference::getDefaultReceivableFinancialCategoryId($this->invoice->company_id);

        return filled($categoryId) ? (int) $categoryId : null;
    }
}
