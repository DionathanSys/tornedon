<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivableInstallments\Actions;

use App\Filament\Clusters\Financial\Resources\Components\SelectFinancialCategory;
use App\Models\FinancialAccount;
use App\Services\AccountReceivable\BulkRegisterReceivablePaymentsService;
use App\Support\Financial\Money;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class BulkRegisterReceivablePaymentsAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('register_bulk_receivable_payments')
            ->label('Registrar recebimentos em massa')
            ->icon('heroicon-o-currency-dollar')
            ->color('success')
            ->authorize(function (Component $livewire): bool {
                return ! method_exists($livewire, 'canOperateTitle') || $livewire->canOperateTitle('settle');
            })
            ->deselectRecordsAfterCompletion()
            ->modalHeading('Recebimentos em massa')
            ->modalDescription('Será quitado o saldo integral das parcelas disponíveis, sem juros, multa ou desconto.')
            ->modalWidth('3xl')
            ->schema(function (Schema $schema, BulkAction $action): Schema {
                $records = $action->getSelectedRecords();
                $needsCategory = $records->contains(fn ($record): bool => ! $record->financial_category_id);
                $summary = sprintf(
                    '%d parcela(s) — total em aberto: %s',
                    $records->count(),
                    self::money($records->sum(fn ($record): int => Money::stored($record, 'balance_amount'))),
                );

                return $schema
                    ->columns(2)
                    ->components([
                        TextEntry::make('selection_summary')
                            ->label('Seleção')
                            ->state($summary)
                            ->columnSpanFull(),
                        Toggle::make('use_due_date')
                            ->label('Usar Dt. Vencimento como Dt de Recebimento?')
                            ->default(true)
                            ->live()
                            ->inline(false)
                            ->columnSpanFull(),
                        DatePicker::make('payment_date')
                            ->label('Data do recebimento')
                            ->default(today())
                            ->visible(fn (Get $get): bool => ! (bool) $get('use_due_date'))
                            ->required(fn (Get $get): bool => ! (bool) $get('use_due_date'))
                            ->dehydrated(fn (Get $get): bool => ! (bool) $get('use_due_date'))
                            ->native(false)
                            ->columnSpan(1),
                        Select::make('financial_account_id')
                            ->label('Conta Financeira')
                            ->options(fn (): array => FinancialAccount::optionsForCompany(Filament::getTenant()->id))
                            ->default(fn (): ?int => FinancialAccount::defaultIdForCompany(Filament::getTenant()->id))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->columnSpan(1),
                        SelectFinancialCategory::make('financial_category_id', 'receivable')
                            ->label('Categoria Financeira')
                            ->visible($needsCategory)
                            ->required($needsCategory)
                            ->dehydrated($needsCategory)
                            ->helperText('Aplicada somente às parcelas selecionadas que ainda não possuem categoria.')
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label('Observações')
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]);
            })
            ->action(function (BulkAction $action, Component $livewire, array $data): void {
                $records = $action->getSelectedRecords();

                if (method_exists($livewire, 'authorizeInstallment')) {
                    foreach ($records as $record) {
                        $livewire->authorizeInstallment('settle', $record);
                    }
                }

                try {
                    $result = app(BulkRegisterReceivablePaymentsService::class)->register(
                        $records,
                        Filament::getTenant(),
                        Filament::auth()->user(),
                        $data,
                        Filament::getCurrentPanel()?->getId(),
                    );
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Não foi possível registrar os recebimentos')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();
                    $action->halt();

                    return;
                }

                $body = sprintf(
                    '%s em %d parcela(s) quitada(s).',
                    self::money($result['amount_cents']),
                    $result['processed'],
                );
                if ($result['skipped'] > 0) {
                    $body .= sprintf(' %d parcela(s) já quitada(s) ou indisponível(is) foram ignoradas.', $result['skipped']);
                }

                Notification::make()
                    ->title('Recebimentos em massa concluídos')
                    ->body($body)
                    ->success()
                    ->send();
            })
            ->after(function (Component $livewire): void {
                $livewire->dispatch('refresh-installments');
                $livewire->dispatch('refresh-payments');
            });
    }

    private static function money(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
