<?php

namespace App\Filament\Shop\Resources\AccountPayables\Pages;

use App\Enum\AccountPayable\Status;
use App\Filament\Shop\Resources\AccountPayables\AccountPayableResource;
use App\Models\AccountPayable;
use App\Models\AccountPayableInstallment;
use App\Models\FinancialAccount;
use App\Notification\NotifyService as notify;
use App\Services\AccountPayable\AccountPayableService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListAccountPayables extends Page
{
    protected static string $resource = AccountPayableResource::class;

    protected string $view = 'filament.shop.resources.account-payables.pages.mobile-list';

    public string $activeTab = 'pending';

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    public bool $showPaymentForm = false;

    public ?int $paymentPayableId = null;

    public array $paymentData = [];

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['pending', Status::PAID->value, 'all'], true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    public function clearDateFilters(): void
    {
        $this->dateFrom = null;
        $this->dateTo = null;
    }

    public function getTitle(): string
    {
        return 'Contas à Pagar';
    }

    public function getHeading(): string
    {
        return 'Contas à Pagar';
    }

    /**
     * @return Collection<int, AccountPayable>
     */
    public function getAccountPayablesProperty(): Collection
    {
        return $this->baseQuery()
            ->when($this->activeTab === 'pending', fn (Builder $query): Builder => $query->whereIn('status', [
                Status::PENDING->value,
                Status::PARTIALLY_PAID->value,
                Status::OVERDUE->value,
            ]))
            ->when($this->activeTab === Status::PAID->value, fn (Builder $query): Builder => $query->where('status', Status::PAID->value))
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->limit(60)
            ->get();
    }

    public function getPendingCountProperty(): int
    {
        return $this->baseQuery()->whereIn('status', [
            Status::PENDING->value,
            Status::PARTIALLY_PAID->value,
            Status::OVERDUE->value,
        ])->count();
    }

    public function getPaidCountProperty(): int
    {
        return $this->baseQuery()->where('status', Status::PAID->value)->count();
    }

    public function getAllCountProperty(): int
    {
        return $this->baseQuery()->count();
    }

    public function getCreateUrl(): string
    {
        return AccountPayableResource::getUrl('create');
    }

    public function getDetailUrl(AccountPayable $record): string
    {
        return AccountPayableResource::getUrl('edit', ['record' => $record->getKey()]);
    }

    public function openRegisterPayment(int $payableId): void
    {
        $installment = $this->findPayableInstallment($payableId);

        if ($installment === null) {
            notify::warning('Nenhuma parcela em aberto encontrada para esta conta.');

            return;
        }

        $this->paymentPayableId = $payableId;
        $this->paymentData = [
            'payment_date' => now()->toDateString(),
            'amount' => $this->formatMoney((float) ($installment->balance_amount ?: $installment->due_amount)),
            'interest_amount' => '0,00',
            'fine_amount' => '0,00',
            'discount_amount' => '0,00',
            'financial_account_id' => FinancialAccount::defaultIdForCompany(Filament::getTenant()->id),
            'description' => $installment->description ?? $installment->accountPayable?->description,
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
        $installment = $this->findPayableInstallment((int) $this->paymentPayableId);

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

        $service = app(AccountPayableService::class);
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
            ]
        );

        if ($service->hasError() || $payment === null) {
            notify::error($service->getMessageUser() ?: 'Erro ao registrar pagamento.');

            return;
        }

        notify::success($service->getMessage() ?: 'Pagamento registrado com sucesso.');
        $this->resetPaymentForm();
    }

    public function getFinancialAccountOptionsProperty(): array
    {
        return FinancialAccount::optionsForCompany(Filament::getTenant()->id);
    }

    private function baseQuery(): Builder
    {
        return AccountPayable::query()
            ->where('company_id', Filament::getTenant()->id)
            ->when($this->dateFrom, fn (Builder $query): Builder => $query->whereDate('due_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn (Builder $query): Builder => $query->whereDate('due_date', '<=', $this->dateTo))
            ->with('supplier');
    }

    private function findPayableInstallment(int $payableId): ?AccountPayableInstallment
    {
        if ($payableId <= 0) {
            return null;
        }

        return AccountPayableInstallment::query()
            ->where('account_payable_id', $payableId)
            ->where('company_id', Filament::getTenant()->id)
            ->whereIn('status', [
                Status::PENDING->value,
                Status::PARTIALLY_PAID->value,
                Status::OVERDUE->value,
            ])
            ->with('accountPayable')
            ->orderBy('sequence_number')
            ->first();
    }

    private function resetPaymentForm(): void
    {
        $this->showPaymentForm = false;
        $this->paymentPayableId = null;
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
