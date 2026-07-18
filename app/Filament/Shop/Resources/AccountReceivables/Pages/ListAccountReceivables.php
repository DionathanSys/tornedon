<?php

namespace App\Filament\Shop\Resources\AccountReceivables\Pages;

use App\Enum\AccountReceivable\Status;
use App\Filament\Shop\Resources\AccountReceivables\AccountReceivableResource;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Models\FinancialAccount;
use App\Notification\NotifyService as notify;
use App\Services\AccountReceivable\AccountReceivableService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListAccountReceivables extends Page
{
    protected static string $resource = AccountReceivableResource::class;

    protected string $view = 'filament.shop.resources.account-receivables.pages.mobile-list';

    public string $activeTab = 'pending';

    public bool $showPaymentForm = false;

    public ?int $paymentReceivableId = null;

    public array $paymentData = [];

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['pending', Status::RECEIVED->value, 'all'], true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    public function getTitle(): string
    {
        return 'Contas à Receber';
    }

    public function getHeading(): string
    {
        return 'Contas à Receber';
    }

    /**
     * @return Collection<int, AccountReceivable>
     */
    public function getAccountReceivablesProperty(): Collection
    {
        return $this->baseQuery()
            ->when($this->activeTab === 'pending', fn (Builder $query): Builder => $query->whereIn('status', [
                Status::PENDING->value,
                Status::PARTIALLY_RECEIVED->value,
                Status::OVERDUE->value,
            ]))
            ->when($this->activeTab === Status::RECEIVED->value, fn (Builder $query): Builder => $query->where('status', Status::RECEIVED->value))
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->limit(60)
            ->get();
    }

    public function getPendingCountProperty(): int
    {
        return $this->baseQuery()->whereIn('status', [
            Status::PENDING->value,
            Status::PARTIALLY_RECEIVED->value,
            Status::OVERDUE->value,
        ])->count();
    }

    public function getReceivedCountProperty(): int
    {
        return $this->baseQuery()->where('status', Status::RECEIVED->value)->count();
    }

    public function getAllCountProperty(): int
    {
        return $this->baseQuery()->count();
    }

    public function getCreateUrl(): string
    {
        return AccountReceivableResource::getUrl('create');
    }

    public function getDetailUrl(AccountReceivable $record): string
    {
        return AccountReceivableResource::getUrl('edit', ['record' => $record->getKey()]);
    }

    public function openRegisterPayment(int $receivableId): void
    {
        $installment = $this->findReceivableInstallment($receivableId);

        if ($installment === null) {
            notify::warning('Nenhuma parcela em aberto encontrada para esta conta.');

            return;
        }

        $this->paymentReceivableId = $receivableId;
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
        $installment = $this->findReceivableInstallment((int) $this->paymentReceivableId);

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
            ]
        );

        if ($service->hasError() || $payment === null) {
            notify::error($service->getMessageUser() ?: 'Erro ao registrar recebimento.');

            return;
        }

        notify::success($service->getMessage() ?: 'Recebimento registrado com sucesso.');
        $this->resetPaymentForm();
    }

    public function getFinancialAccountOptionsProperty(): array
    {
        return FinancialAccount::optionsForCompany(Filament::getTenant()->id);
    }

    private function baseQuery(): Builder
    {
        return AccountReceivable::query()
            ->where('company_id', Filament::getTenant()->id)
            ->with('customer');
    }

    private function findReceivableInstallment(int $receivableId): ?AccountReceivableInstallment
    {
        if ($receivableId <= 0) {
            return null;
        }

        return AccountReceivableInstallment::query()
            ->where('account_receivable_id', $receivableId)
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
        $this->paymentReceivableId = null;
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
