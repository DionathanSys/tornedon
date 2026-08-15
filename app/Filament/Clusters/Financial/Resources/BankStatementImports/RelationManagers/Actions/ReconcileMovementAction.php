<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions;

use App\Filament\Clusters\Financial\Resources\BankStatementImports\Tables\StatementLineCashMovementsTable;
use App\Models\BankStatementLine;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use Filament\Actions\Action;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Livewire\Component;

final class ReconcileMovementAction
{
    public static function make(): Action
    {
        return Action::make('reconcile_movement')
            ->label('Vincular movimento')
            ->icon('heroicon-o-link')
            ->color('info')
            ->schema(fn (Schema $schema) => $schema->components([
                ModalTableSelect::make('cash_movement_id')
                    ->label('Movimento financeiro')
                    ->saved(false)
                    ->tableConfiguration(StatementLineCashMovementsTable::class)
                    ->tableArguments(fn (BankStatementLine $record): array => [
                        'financial_account_id' => $record->financial_account_id,
                    ])
                    ->selectAction(fn (Action $action): Action => $action
                        ->modalHeading('Buscar movimento financeiro')
                        ->modalWidth(Width::SevenExtraLarge))
                    ->required(),
            ]))
            ->visible(fn (BankStatementLine $record): bool => $record->reconciliation_status?->value !== 'reconciled')
            ->action(function (BankStatementLine $record, array $data): void {
                $service = app(ResolveBankStatementLineService::class);
                $resolved = $service->reconcileWithCashMovement($record, (int) $data['cash_movement_id'], auth()->id());

                if ($service->hasError() || $resolved === null) {
                    Notification::make()
                        ->title($service->getMessageUser() ?: 'Erro ao conciliar com movimento.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($service->getMessage() ?: 'Linha conciliada com sucesso.')
                    ->success()
                    ->send();
            })
            ->after(function (Component $livewire): void {
                $livewire->dispatch('refresh-statement-lines');
            });
    }
}
