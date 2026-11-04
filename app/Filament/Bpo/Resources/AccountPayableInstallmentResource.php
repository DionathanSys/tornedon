<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Bpo\Resources\AccountPayableInstallments\Pages\ListInstallments;
use App\Filament\Clusters\Financial\Resources\AccountPayableInstallments\Tables\AccountPayableInstallmentsTable;
use App\Models\AccountPayableInstallment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountPayableInstallmentResource extends Resource
{
    protected static ?string $model = AccountPayableInstallment::class;

    public static string $module = 'account_payables';

    protected static ?string $modelLabel = 'parcela a pagar';

    protected static ?string $pluralModelLabel = 'Parcelas a Pagar';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id)
            ->with(['accountPayable.supplier', 'financialCategory']);
    }

    public static function table(Table $table): Table
    {
        $table = AccountPayableInstallmentsTable::configure($table);
        $accountUrl = fn (AccountPayableInstallment $record): string => AccountPayableResource::getUrl('view', ['record' => $record->account_payable_id]);

        $table->getColumn('accountPayable.supplier.name')->url($accountUrl);
        $table->getColumn('financialCategory.full_name')
            ->state(fn (ListInstallments $livewire, AccountPayableInstallment $record): ?string => $livewire->categoryLabel($record));

        // Avoid reapplying role scopes for separate page and total sums on every render.
        foreach ($table->getColumns() as $column) {
            foreach ($column->getSummarizers() as $summarizer) {
                $summarizer->hidden();
            }
        }

        foreach ($table->getRecordActions() as $group) {
            if (! $group instanceof ActionGroup) {
                continue;
            }

            $group->icon(Heroicon::Bars3)->label('Ações da parcela')->tooltip('Ações da parcela')->iconButton();

            foreach ($group->getFlatActions() as $action) {
                $ability = match ($action->getName()) {
                    'register_payment' => 'settle',
                    'open_account' => 'view',
                    'ignore_financial', 'restore_consideration' => 'change_status_without_movement',
                    default => 'update',
                };

                $action->tooltip(fn (Action $action) => $action->getLabel())
                    ->authorize(fn (ListInstallments $livewire, AccountPayableInstallment $record): bool => $livewire->canOperateInstallment($ability, $record))
                    ->beforeFormFilled(fn (ListInstallments $livewire, AccountPayableInstallment $record) => $livewire->authorizeInstallment($ability, $record))
                    ->before(fn (ListInstallments $livewire, AccountPayableInstallment $record) => $livewire->authorizeInstallment($ability, $record));

                if (in_array($action->getName(), ['ignore_financial', 'restore_consideration'], true)) {
                    $restore = $action->getName() === 'restore_consideration';
                    $action->visible(fn (AccountPayableInstallment $record): bool => (bool) $record->ignored_at === $restore);
                }

                if ($action->getName() === 'open_account') {
                    $action->label('Abrir conta')->url($accountUrl);
                }
            }
        }

        return $table->recordUrl($accountUrl)
            ->recordActionsPosition(RecordActionsPosition::AfterColumns)
            ->toolbarActions([])->bulkActions([])
            ->defaultPaginationPageOption(15)->paginated([15, 30, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => ListInstallments::route('/')];
    }
}
