<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\Actions;

use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivableInstallment;
use App\Models\BankStatementLine;
use App\Models\CashMovement;
use App\Services\Financial\BankStatement\ReconcileBankStatementGroupService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class ReconcileMultipleAction
{
    public static function make(bool $bulk = false): Action
    {
        $action = $bulk ? BulkAction::make('reconcile_multiple') : Action::make('reconcile_multiple');
        $action->label($bulk ? 'Conciliar selecionados' : 'Conciliação múltipla')
            ->icon('heroicon-o-link')->color('info')
            ->modalHeading('Conciliação múltipla')->modalWidth('4xl')
            ->schema(function (Action $action) use ($bulk): array {
                $lines = $bulk ? $action->getSelectedRecords() : collect([$action->getRecord()]);
                $first = $lines->first();
                $total = $lines->sum(fn ($line) => (int) round((float) $line->amount * 100)) / 100;

                return [
                    Text::make($lines->map(fn ($line) => sprintf('#%d • %s • %s • R$ %s', $line->id, $line->transaction_date->format('d/m/Y'), $line->description, number_format((float) $line->amount, 2, ',', '.')))->implode("\n"))->extraAttributes(['class' => 'whitespace-pre-line']),
                    Select::make('type')->label('Conciliar com')->options([
                        'cash_movement' => 'Movimentos já registrados',
                        ($first?->isInflow() ? 'receivable' : 'payable') => $first?->isInflow() ? 'Parcelas a receber em aberto' : 'Parcelas a pagar em aberto',
                    ])->default('cash_movement')->required()->live()
                        ->afterStateUpdated(fn (Set $set) => $set('items', [])),
                    Repeater::make('items')->label('Itens do sistema')->minItems(1)->defaultItems(1)->live()
                        ->addActionLabel('Adicionar parcela ou movimento')->columns(3)->schema([
                            Select::make('id')->label('Parcela / movimento')->columnSpan(2)->required()->searchable()->live()
                                ->getSearchResultsUsing(fn (string $search, Get $get) => self::options($get('../../type'), $first, $search))
                                ->getOptionLabelUsing(fn ($value, Get $get) => self::options($get('../../type'), $first, id: $value)[$value] ?? null)
                                ->afterStateUpdated(function ($state, Get $get, Set $set) use ($first): void {
                                    $type = $get('../../type');
                                    $target = self::query($type, $first)->find($state);
                                    $set('amount', $target ? (float) ($type === 'cash_movement' ? $target->amount : $target->balance_amount) : 0);
                                }),
                            TextInput::make('amount')->label('Valor (R$)')->numeric()->minValue(0.01)->step(0.01)->required()->live(onBlur: true)
                                ->readOnly(fn (Get $get) => $get('../../type') === 'cash_movement'),
                        ]),
                    Text::make(function (Get $get) use ($total): string {
                        $distributed = collect($get('items') ?? [])->sum(fn ($item) => is_numeric($item['amount'] ?? null) ? (float) $item['amount'] : 0);

                        return sprintf('Total do extrato: R$ %s | Distribuído: R$ %s | Diferença: R$ %s', number_format($total, 2, ',', '.'), number_format($distributed, 2, ',', '.'), number_format($total - $distributed, 2, ',', '.'));
                    }),
                    Textarea::make('exception_reason')->label('Justificativa de exceção de data')->helperText('Obrigatória para movimentos fora da margem de data. Os totais devem ser iguais.')->rows(2),
                ];
            })
            ->action(function (Action $action, array $data) use ($bulk): void {
                try {
                    app(ReconcileBankStatementGroupService::class)->reconcile(
                        Filament::getTenant()->id,
                        $bulk ? $action->getSelectedRecords()->pluck('id')->all() : [$action->getRecord()->id],
                        $data['type'], $data['items'], auth()->id(), $data['exception_reason'] ?? null,
                    );
                } catch (ValidationException $e) {
                    Notification::make()->title('Não foi possível conciliar.')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();
                    $action->halt();
                }
                Notification::make()->title('Conciliação múltipla realizada.')->success()->send();
            })
            ->after(fn (Component $livewire) => $livewire->dispatch('refresh-statement-lines'));
        if ($bulk) {
            $action->deselectRecordsAfterCompletion();
        } else {
            $action->visible(fn (BankStatementLine $record): bool => $record->reconciliation_status?->canResolve() === true);
        }

        return $action;
    }

    private static function query(?string $type, BankStatementLine $line): Builder
    {
        $model = match ($type) {
            'receivable' => AccountReceivableInstallment::class,
            'payable' => AccountPayableInstallment::class,
            default => CashMovement::class,
        };
        $query = $model::query()->where('company_id', Filament::getTenant()->id);
        if ($type === 'cash_movement') {
            return $query->where('financial_account_id', $line->financial_account_id)->where('direction', $line->direction()?->value)
                ->whereNull('reversed_at')->whereNull('reversal_of_id')->whereDoesntHave('statementLines')->whereDoesntHave('statementAllocations');
        }

        return $query->where('balance_amount', '>', 0);
    }

    private static function options(?string $type, BankStatementLine $line, string $search = '', mixed $id = null): array
    {
        return self::query($type, $line)->when($id, fn ($query) => $query->whereKey($id))
            ->when($search !== '', fn ($query) => $query->where('description', 'like', '%'.$search.'%'))
            ->orderBy('id')->limit(50)->get()->mapWithKeys(fn ($target) => [$target->id => sprintf(
                '#%d • %s • %s • R$ %s', $target->id, $target->description,
                ($type === 'cash_movement' ? $target->transaction_date : $target->due_date)?->format('d/m/Y'),
                number_format((float) ($type === 'cash_movement' ? $target->amount : $target->balance_amount), 2, ',', '.'),
            )])->all();
    }
}
