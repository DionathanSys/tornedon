<?php

namespace App\Filament\Shop\Resources\AccountReceivables\Pages;

use App\Enum\AccountReceivable\Status;
use App\Filament\Shop\Resources\AccountReceivables\AccountReceivableResource;
use App\Models\AccountReceivable;
use App\Models\AccountReceivableInstallment;
use App\Models\FinancialAccount;
use App\Services\AccountReceivable\AccountReceivableService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ListAccountReceivables extends Page
{
    protected static string $resource = AccountReceivableResource::class;

    protected string $view = 'filament.shop.resources.account-receivables.pages.mobile-list';

    public string $activeTab = 'open';

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['open', Status::OVERDUE->value, Status::RECEIVED->value, 'all'], true)) {
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
            ->when($this->activeTab === 'open', fn (Builder $query): Builder => $query->whereIn('status', [
                Status::PENDING->value,
                Status::PARTIALLY_RECEIVED->value,
            ]))
            ->when($this->activeTab === Status::OVERDUE->value, fn (Builder $query): Builder => $query->where('status', Status::OVERDUE->value))
            ->when($this->activeTab === Status::RECEIVED->value, fn (Builder $query): Builder => $query->where('status', Status::RECEIVED->value))
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->limit(60)
            ->get();
    }

    public function getOpenCountProperty(): int
    {
        return $this->baseQuery()->whereIn('status', [
            Status::PENDING->value,
            Status::PARTIALLY_RECEIVED->value,
        ])->count();
    }

    public function getOverdueCountProperty(): int
    {
        return $this->baseQuery()->where('status', Status::OVERDUE->value)->count();
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
        $this->mountAction('registerPayment', ['receivable' => $receivableId]);
    }

    public function registerPaymentAction(): Action
    {
        return Action::make('registerPayment')
            ->label('Registrar recebimento')
            ->icon('heroicon-o-currency-dollar')
            ->color('success')
            ->schema(fn (Schema $schema) => $schema
                ->columns(2)
                ->components([
                    DatePicker::make('payment_date')
                        ->label('Data do recebimento')
                        ->default(now())
                        ->required(),
                    Money::make('amount')
                        ->label('Valor recebido')
                        ->required(),
                    Money::make('interest_amount')->label('Juros'),
                    Money::make('fine_amount')->label('Multa'),
                    Money::make('discount_amount')->label('Desconto'),
                    TextInput::make('bank_account_id')
                        ->label('Conta bancaria (ID)')
                        ->visible(false)
                        ->numeric(),
                    Select::make('financial_account_id')
                        ->label('Conta Financeira')
                        ->options(fn (): array => FinancialAccount::optionsForCompany(Filament::getTenant()->id))
                        ->default(fn (): ?int => FinancialAccount::defaultIdForCompany(Filament::getTenant()->id))
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required(),
                    Textarea::make('description')
                        ->label('Descrição do Movimento')
                        ->rows(2)
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Textarea::make('notes')
                        ->label('Observações')
                        ->rows(3)
                        ->columnSpanFull(),
                ]))
            ->fillForm(function (array $arguments): array {
                $installment = $this->findReceivableInstallment((int) ($arguments['receivable'] ?? 0));

                return [
                    'payment_date' => now()->toDateString(),
                    'amount' => $installment?->balance_amount ?: $installment?->due_amount,
                    'description' => $installment?->description ?? $installment?->accountReceivable?->description,
                ];
            })
            ->action(function (array $arguments, array $data): void {
                $installment = $this->findReceivableInstallment((int) ($arguments['receivable'] ?? 0));

                if ($installment === null) {
                    Notification::make()
                        ->title('Nenhuma parcela em aberto encontrada para esta conta.')
                        ->warning()
                        ->send();

                    return;
                }

                $service = app(AccountReceivableService::class);
                $payment = $service->registerInstallmentPayment(
                    $installment,
                    (float) ($data['amount'] ?? 0),
                    (string) ($data['payment_date'] ?? ''),
                    [
                        'interest_amount' => (float) ($data['interest_amount'] ?? 0),
                        'fine_amount' => (float) ($data['fine_amount'] ?? 0),
                        'discount_amount' => (float) ($data['discount_amount'] ?? 0),
                        'bank_account_id' => $data['bank_account_id'] ?? null,
                        'financial_account_id' => $data['financial_account_id'] ?? null,
                        'description' => $data['description'] ?? null,
                        'notes' => $data['notes'] ?? null,
                    ]
                );

                if ($service->hasError() || $payment === null) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao registrar recebimento.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Recebimento registrado com sucesso.')
                    ->success()
                    ->send();
            });
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
}
