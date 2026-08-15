<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions;

use App\Filament\Clusters\Financial\Resources\BankStatementImports\Tables\StatementLinePayableInstallmentsTable;
use App\Models\BankStatementLine;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Leandrocfe\FilamentPtbrFormFields\Money;
use Livewire\Component;

final class ReconcilePayableInstallmentAction
{
    public static function make(): Action
    {
        return Action::make('reconcile_payable_installment')
            ->label('Baixar conta a pagar')
            ->icon('heroicon-o-arrow-down-circle')
            ->color('warning')
            ->visible(fn (BankStatementLine $record): bool => $record->isOutflow() && $record->reconciliation_status?->canResolve() === true)
            ->schema(fn (Schema $schema) => $schema
                ->columns(2)
                ->components([
                    ModalTableSelect::make('installment_id')
                        ->label('Parcela')
                        ->saved(false)
                        ->tableConfiguration(StatementLinePayableInstallmentsTable::class)
                        ->selectAction(fn (Action $action): Action => $action
                            ->modalHeading('Buscar parcela a pagar')
                            ->modalWidth(Width::SevenExtraLarge))
                        ->required()
                        ->columnSpanFull(),
                    DatePicker::make('payment_date')
                        ->label('Data do pagamento')
                        ->default(fn (BankStatementLine $record) => $record->transaction_date)
                        ->required(),
                    Money::make('interest_amount')
                        ->label('Juros')
                        ->default(0),
                    Money::make('fine_amount')
                        ->label('Multa')
                        ->default(0),
                    Money::make('discount_amount')
                        ->label('Desconto')
                        ->default(0),
                    Textarea::make('notes')
                        ->label('Observações')
                        ->rows(3)
                        ->columnSpanFull(),
                ]))
            ->action(function (BankStatementLine $record, array $data): void {
                $service = app(ResolveBankStatementLineService::class);
                $resolved = $service->reconcileWithPayableInstallment($record, (int) $data['installment_id'], $data, auth()->id());

                if ($service->hasError() || $resolved === null) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao baixar parcela a pagar.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Parcela baixada e linha conciliada com sucesso.')
                    ->success()
                    ->send();
            })
            ->after(function (Component $livewire): void {
                $livewire->dispatch('refresh-statement-lines');
            });
    }
}
