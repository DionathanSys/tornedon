<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions;

use App\Forms\Components\BankReconciliationWorkbench;
use App\Models\BankStatementLine;
use App\Services\Financial\BankStatement\ReconcileBankStatementGroupService;
use App\Services\Financial\BankStatement\ReconcileReceiptWithRetainedExpensesService;
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
                    if (($selection['type'] ?? '') === 'retained_receipt') {
                        $items = array_map(function (array $item): array {
                            // The workbench's amount_cents describes the available balance.
                            // Only gross_cents is the explicit receipt amount in this mode.
                            $item['amount_cents'] = $item['gross_cents'] ?? null;

                            return $item;
                        }, $selection['items'] ?? []);
                        app(ReconcileReceiptWithRetainedExpensesService::class)->reconcile(
                            Filament::getTenant()->id,
                            $bulk ? $action->getSelectedRecords()->pluck('id')->all() : [$action->getRecord()->id],
                            $items, $selection['idempotency_key'] ?? '', auth()->id(), $selection['evaluation_token'] ?? null,
                        );
                    } else {
                        app(ReconcileBankStatementGroupService::class)->reconcile(
                            Filament::getTenant()->id,
                            $bulk ? $action->getSelectedRecords()->pluck('id')->all() : [$action->getRecord()->id],
                            $selection['type'] ?? '', $selection['items'] ?? [], auth()->id(), $selection['exception_reason'] ?? null,
                            $selection['evaluation_token'] ?? null,
                        );
                    }
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
