<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions;

use App\Forms\Components\BankReconciliationWorkbench;
use App\Models\BankStatementLine;
use App\Services\Financial\BankStatement\ReconcileBankStatementGroupService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class ReconcileMultipleAction
{
    public static function make(bool $bulk = false): Action
    {
        $action = $bulk ? BulkAction::make('reconcile_multiple') : Action::make('reconcile_multiple');
        $action->label($bulk ? 'Conciliar selecionados' : 'Conciliar registros')
            ->icon('heroicon-o-arrows-right-left')
            ->color('info')
            ->modalHeading('Conciliação bancária')
            ->modalDescription('Confira o extrato, selecione os registros correspondentes e feche os valores.')
            ->modalWidth('7xl')
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
            ->closeModalByClickingAway(false)
            ->schema(fn (Action $action): array => [
                BankReconciliationWorkbench::make('selection')
                    ->label('Seleção para conciliação')
                    ->hiddenLabel()
                    ->statementLines($bulk ? $action->getSelectedRecords() : collect([$action->getRecord()]))
                    ->default(['type' => 'cash_movement', 'items' => [], 'exception_reason' => ''])
                    ->required()
                    ->rules(['array']),
            ])
            ->action(function (Action $action, array $data) use ($bulk): void {
                $selection = $data['selection'];
                try {
                    app(ReconcileBankStatementGroupService::class)->reconcile(
                        Filament::getTenant()->id,
                        $bulk ? $action->getSelectedRecords()->pluck('id')->all() : [$action->getRecord()->id],
                        $selection['type'] ?? '', $selection['items'] ?? [], auth()->id(), $selection['exception_reason'] ?? null,
                    );
                } catch (ValidationException $e) {
                    Notification::make()->title('Confira a seleção antes de conciliar')
                        ->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();
                    $action->halt();
                }
                Notification::make()->title('Conciliação realizada com sucesso.')->success()->send();
            })
            ->after(fn (Component $livewire) => $livewire->dispatch('refresh-statement-lines'));
        if ($bulk) {
            $action->deselectRecordsAfterCompletion();
        } else {
            $action->visible(fn (BankStatementLine $record): bool => $record->reconciliation_status?->canResolve() === true);
        }

        return $action;
    }
}
