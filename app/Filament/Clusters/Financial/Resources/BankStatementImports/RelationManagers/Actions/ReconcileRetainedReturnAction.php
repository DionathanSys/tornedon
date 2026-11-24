<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions;

use App\Models\BankReconciliationGroup;
use App\Models\BankStatementLine;
use App\Services\Financial\BankStatement\ReconcileRetainedReceiptReturnService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

final class ReconcileRetainedReturnAction
{
    public static function make(): Action
    {
        return Action::make('reconcile_retained_return')->label('Conciliar devolução de recebimento')->icon('heroicon-o-arrow-uturn-left')->color('warning')
            ->visible(fn (BankStatementLine $record) => $record->isOutflow() && $record->reconciliation_status?->canResolve())
            ->modalDescription('Vincula esta saída aos movimentos de uma devolução já registrada. Não cria pagamentos nem movimentos adicionais.')
            ->schema([
                Select::make('source_group_id')->label('Operação devolvida')->required()->searchable()
                    ->options(fn () => BankReconciliationGroup::where('company_id', Filament::getTenant()->id)->where('operation_type', 'retained_receipt')->where('status', 'returned')->orderByDesc('id')->get()->mapWithKeys(fn ($group) => [$group->id => 'Operação #'.$group->id.' — devolução em '.($group->metadata['return_date'] ?? '')])->all()),
            ])
            ->action(function (BankStatementLine $record, array $data, Action $action): void {
                try {
                    app(ReconcileRetainedReceiptReturnService::class)->reconcile(Filament::getTenant()->id, $record->id, (int) $data['source_group_id'], auth()->id());
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Confira a devolução')->body(collect($exception->errors())->flatten()->implode(' '))->send();
                    $action->halt();
                }
                Notification::make()->success()->title('Devolução conciliada')->send();
            });
    }
}
