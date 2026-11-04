<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Bpo\Resources\Pages\ListFinancialInstallments;
use App\Filament\Clusters\Financial\Resources\AccountPayableInstallments\Tables\AccountPayableInstallmentsTable;
use App\Filament\Clusters\Financial\Resources\AccountReceivableInstallments\Tables\AccountReceivableInstallmentsTable;
use App\Filament\Clusters\Financial\Resources\Components\InstallmentEditForm;
use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivableInstallment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

abstract class FinancialInstallmentResource extends Resource
{
    public static string $module;

    public static function accountResource(): string
    {
        return static::$module === 'account_payables' ? AccountPayableResource::class : AccountReceivableResource::class;
    }

    public static function accountRelationship(): string
    {
        return static::$module === 'account_payables' ? 'accountPayable' : 'accountReceivable';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id)
            ->with([static::$module === 'account_payables' ? 'accountPayable.supplier' : 'accountReceivable.customer', 'financialCategory']);
    }

    public static function table(Table $table): Table
    {
        $payable = static::$module === 'account_payables';
        $table = $payable ? AccountPayableInstallmentsTable::configure($table) : AccountReceivableInstallmentsTable::configure($table);
        $accountUrl = fn (AccountPayableInstallment|AccountReceivableInstallment $record): string => static::accountResource()::getUrl('view', ['record' => $record->{static::accountRelationship()}]);

        $table->getColumn($payable ? 'accountPayable.supplier.name' : 'accountReceivable.counterparty_label')->url($accountUrl);
        $table->getColumn('financialCategory.full_name')
            ->state(fn (ListFinancialInstallments $livewire, AccountPayableInstallment|AccountReceivableInstallment $record): ?string => $livewire->categoryLabel($record));

        $table->columns(array_map(fn ($column) => $column->getName() === 'sequence_number'
            ? TextColumn::make('id')->label('Conta')->formatStateUsing(fn ($state): string => '#'.$state)->sortable()
            : $column, $table->getColumns()));

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

            $group->icon(Heroicon::Bars3)->label('Ações da conta')->tooltip('Ações da conta')->iconButton();

            foreach ($group->getFlatActions() as $action) {
                $ability = match ($action->getName()) {
                    'register_payment' => 'settle',
                    'open_account' => 'view',
                    'ignore_financial', 'restore_consideration' => 'change_status_without_movement',
                    default => 'update',
                };

                $action->tooltip(fn (Action $action) => $action->getLabel())
                    ->authorize(fn (ListFinancialInstallments $livewire, AccountPayableInstallment|AccountReceivableInstallment $record): bool => $livewire->canOperateInstallment($ability, $record))
                    ->beforeFormFilled(fn (ListFinancialInstallments $livewire, AccountPayableInstallment|AccountReceivableInstallment $record) => $livewire->authorizeInstallment($ability, $record))
                    ->before(fn (ListFinancialInstallments $livewire, AccountPayableInstallment|AccountReceivableInstallment $record) => $livewire->authorizeInstallment($ability, $record));

                if ($action->getName() === 'edit_installment') {
                    $action->label('Editar conta')
                        ->schema(InstallmentEditForm::components($payable ? 'payable' : 'receivable', asAccount: true))
                        ->modalHeading(fn (AccountPayableInstallment|AccountReceivableInstallment $record): string => 'Editar conta #'.$record->id);
                }

                if ($action->getName() === 'delete_installment') {
                    $action->label('Excluir conta')->modalHeading('Excluir conta');
                }

                if (in_array($action->getName(), ['ignore_financial', 'restore_consideration'], true)) {
                    $restore = $action->getName() === 'restore_consideration';
                    $action->visible(fn (AccountPayableInstallment|AccountReceivableInstallment $record): bool => (bool) $record->ignored_at === $restore)
                        ->modalHeading($restore ? 'Voltar a considerar conta' : 'Ignorar conta')
                        ->modalDescription('Preserva os valores e o histórico desta conta. Ignorar retira seu saldo das pendências e automações, sem registrar baixa. Restaurar volta a considerar a conta.');
                }

                if ($action->getName() === 'open_account') {
                    $action->label('Abrir lançamento de origem')->url($accountUrl);
                }
            }
        }

        return $table->recordTitleAttribute('id')->recordUrl($accountUrl)
            ->emptyStateHeading('Nenhuma conta encontrada')
            ->emptyStateDescription($payable ? 'As contas a pagar aparecerão aqui.' : 'As contas a receber aparecerão aqui.')
            ->recordActionsPosition(RecordActionsPosition::AfterColumns)
            ->toolbarActions([])->bulkActions([])
            ->defaultPaginationPageOption(15)->paginated([15, 30, 50]);
    }
}
