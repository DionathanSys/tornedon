<?php

namespace App\Filament\Shop\Resources\AccountReceivables\Pages;

use App\Enum\AccountReceivable\Status;
use App\Filament\Shop\Resources\AccountReceivables\AccountReceivableResource;
use App\Models\AccountReceivableInstallment;
use App\Models\FinancialAccount;
use App\Notification\NotifyService as notify;
use App\Services\AccountReceivable\AccountReceivableService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EditAccountReceivable extends EditRecord
{
    protected static string $resource = AccountReceivableResource::class;

    protected string $view = 'filament.shop.resources.account-receivables.pages.mobile-edit';

    public bool $showPaymentForm = false;

    public array $paymentData = [];

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getListUrl(): string
    {
        return AccountReceivableResource::getUrl();
    }

    public function deleteRecord(): void
    {
        $service = app(AccountReceivableService::class);
        $result = $service->delete($this->record);

        if ($service->hasError() || ! $result) {
            Log::error('Shop EditAccountReceivable: Erro ao deletar conta a receber', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'error_code' => $service->getErrorCode(),
                'message' => $service->getMessage(),
                'account_receivable_id' => $this->record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode(),
            );

            return;
        }

        notify::success('Conta a receber excluída com sucesso.');
        $this->redirect(AccountReceivableResource::getUrl(), navigate: true);
    }

    public function openRegisterPayment(): void
    {
        $installment = $this->findReceivableInstallment();

        if ($installment === null) {
            notify::warning('Nenhuma parcela em aberto encontrada para esta conta.');

            return;
        }

        $this->paymentData = [
            'payment_date' => now()->toDateString(),
            'amount' => $this->formatMoney((float) ($installment->balance_amount ?: $installment->due_amount)),
            'interest_amount' => '0,00',
            'fine_amount' => '0,00',
            'discount_amount' => '0,00',
            'financial_account_id' => FinancialAccount::defaultIdForCompany(Filament::getTenant()->id),
            'description' => $installment->description ?? $installment->accountReceivable?->description,
            'notes' => null,
        ];
        $this->showPaymentForm = true;
    }

    public function cancelRegisterPayment(): void
    {
        $this->resetPaymentForm();
    }

    public function savePayment(): void
    {
        $installment = $this->findReceivableInstallment();

        if ($installment === null) {
            notify::warning('Nenhuma parcela em aberto encontrada para esta conta.');
            $this->resetPaymentForm();

            return;
        }

        $data = validator($this->paymentData, [
            'payment_date' => ['required', 'date'],
            'amount' => ['required'],
            'interest_amount' => ['nullable'],
            'fine_amount' => ['nullable'],
            'discount_amount' => ['nullable'],
            'financial_account_id' => ['required', 'integer'],
            'description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ])->validate();

        $service = app(AccountReceivableService::class);
        $payment = $service->registerInstallmentPayment(
            $installment,
            $this->toDecimal($data['amount'] ?? 0),
            (string) $data['payment_date'],
            [
                'interest_amount' => $this->toDecimal($data['interest_amount'] ?? 0),
                'fine_amount' => $this->toDecimal($data['fine_amount'] ?? 0),
                'discount_amount' => $this->toDecimal($data['discount_amount'] ?? 0),
                'financial_account_id' => $data['financial_account_id'] ?? null,
                'description' => $data['description'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => Auth::id(),
            ]
        );

        if ($service->hasError() || $payment === null) {
            notify::error($service->getMessageUser() ?: 'Erro ao registrar recebimento.');

            return;
        }

        $this->record->refresh();
        $this->form->fill($this->record->attributesToArray());
        $this->resetPaymentForm();

        notify::success($service->getMessage() ?: 'Recebimento registrado com sucesso.');
    }

    public function getFinancialAccountOptionsProperty(): array
    {
        return FinancialAccount::optionsForCompany(Filament::getTenant()->id);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = app(AccountReceivableService::class);
        $updated = $service->update($record, $data, Auth::id());

        if ($service->hasError() || $updated === null) {
            Log::error($service->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'error_code' => $service->getErrorCode(),
                'message' => $service->getMessage(),
                'errors' => $service->getErrors(),
                'account_receivable_id' => $record->id,
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode(),
            );

            $this->halt();
        }

        return $updated;
    }

    protected function getUpdatedNotificationTitle(): ?string
    {
        return 'Conta a receber atualizada com sucesso';
    }

    private function findReceivableInstallment(): ?AccountReceivableInstallment
    {
        return AccountReceivableInstallment::query()
            ->where('account_receivable_id', $this->record->getKey())
            ->where('company_id', Filament::getTenant()->id)
            ->whereIn('status', [
                Status::PENDING->value,
                Status::PARTIALLY_RECEIVED->value,
                Status::OVERDUE->value,
            ])
            ->with('accountReceivable')
            ->orderBy('sequence_number')
            ->first();
    }

    private function resetPaymentForm(): void
    {
        $this->showPaymentForm = false;
        $this->paymentData = [];
    }

    private function toDecimal(mixed $value): float
    {
        $normalized = trim((string) $value);

        if (str_contains($normalized, ',')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }

        return (float) $normalized;
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}
