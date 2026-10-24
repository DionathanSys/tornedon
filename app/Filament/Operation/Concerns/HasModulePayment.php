<?php

namespace App\Filament\Operation\Concerns;

use App\Filament\Operation\OperationResourceSupport as Support;
use App\Models\FinancialAccount;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

trait HasModulePayment
{
    public bool $showPaymentForm = false;

    #[Locked]
    public ?int $paymentRecordId = null;

    public array $paymentData = [];

    private function paymentInstallment(int $id): mixed
    {
        abort_unless(in_array($this->moduleKey(), ['account_receivables', 'account_payables'], true), 403);
        $record = Support::query($this->moduleKey())->findOrFail($id);

        return $record->installments()->where('company_id', Filament::getTenant()->id)
            ->open()->orderBy('due_date')->orderBy('sequence_number')->first();
    }

    public function openRegisterPayment(int $id): void
    {
        $this->resetValidation();
        $installment = $this->paymentInstallment($id);
        if (! $installment) {
            $this->warning('Nenhuma parcela em aberto encontrada para esta conta.');

            return;
        }
        $this->paymentRecordId = $id;
        $this->paymentData = ['payment_date' => today()->toDateString(), 'amount' => number_format((float) $installment->balance_amount, 2, ',', '.'), 'interest_amount' => '0,00', 'fine_amount' => '0,00', 'discount_amount' => '0,00', 'financial_account_id' => FinancialAccount::defaultIdForCompany(Filament::getTenant()->id), 'description' => $installment->description, 'notes' => null];
        $this->showPaymentForm = true;
    }

    public function savePayment(): void
    {
        $this->resetValidation();
        abort_unless($this->showPaymentForm && $this->paymentRecordId, 403);
        $installment = $this->paymentInstallment($this->paymentRecordId);
        if (! $installment) {
            $this->warning('A parcela não está mais em aberto. Atualize a conta.');
            $this->showPaymentForm = false;

            return;
        }
        $data = $this->paymentData;
        foreach (['amount', 'interest_amount', 'fine_amount', 'discount_amount'] as $field) {
            if (filled($data[$field] ?? null)) {
                $data[$field] = Support::normalizeNumber($data[$field]);
            }
        }
        $data = Validator::make(['paymentData' => $data], [
            'paymentData.payment_date' => 'required|date', 'paymentData.amount' => 'required|numeric|gt:0',
            'paymentData.interest_amount' => 'nullable|numeric|min:0', 'paymentData.fine_amount' => 'nullable|numeric|min:0', 'paymentData.discount_amount' => 'nullable|numeric|min:0',
            'paymentData.financial_account_id' => ['required', Rule::in(array_keys(FinancialAccount::optionsForCompany(Filament::getTenant()->id)))],
            'paymentData.description' => 'nullable|string|max:255', 'paymentData.notes' => 'nullable|string',
        ])->validate()['paymentData'];
        $service = app(Support::definition($this->moduleKey())['service']);
        $payment = $service->registerInstallmentPayment($installment, $data['amount'], $data['payment_date'], [...$data, 'user_id' => auth()->id()]);
        if ($service->hasError() || ! $payment) {
            $this->serviceFailed($service, 'paymentData');

            return;
        }
        $this->showPaymentForm = false;
        $this->paymentRecordId = null;
        $this->success($service->getMessage() ?: 'Pagamento registrado com sucesso.');
        if (isset($this->record)) {
            $this->record->refresh();
        }
    }

    public function getFinancialAccountOptionsProperty(): array
    {
        return FinancialAccount::optionsForCompany(Filament::getTenant()->id);
    }
}
