<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions;

use App\Filament\Clusters\Financial\Resources\BankStatementImports\Tables\StatementLineCashMovementsTable;
use App\Models\BankStatementLine;
use App\Services\Financial\BankStatement\ResolveBankStatementLineService;
use Filament\Actions\Action;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Textarea;
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
                        'bank_statement_line_id' => $record->id,
                    ])
                    ->selectAction(fn (Action $action): Action => $action
                        ->modalHeading('Buscar movimento financeiro')
                        ->modalWidth(Width::SevenExtraLarge))
                    ->required(),
                Textarea::make('exception_reason')
                    ->label('Justificativa de exceção')
                    ->helperText('Obrigatória apenas se o movimento selecionado estiver fora da margem de valor ou data.')
                    ->rows(3),
            ]))
            ->visible(fn (BankStatementLine $record): bool => $record->reconciliation_status?->canResolve() === true)
            ->action(function (BankStatementLine $record, array $data): void {
                $service = app(ResolveBankStatementLineService::class);
                $resolved = $service->reconcileWithCashMovement($record, (int) $data['cash_movement_id'], auth()->id(), [
                    'exception_reason' => $data['exception_reason'] ?? null,
                ]);

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
