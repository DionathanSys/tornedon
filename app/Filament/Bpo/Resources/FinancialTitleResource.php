<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Bpo\Resources\Actions\FinancialTitleActions;
use App\Filament\Clusters\Financial\Resources\AccountPayables\Schemas\AccountPayableForm;
use App\Filament\Clusters\Financial\Resources\AccountPayables\Tables\AccountPayablesTable;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\Schemas\AccountReceivableForm;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\Tables\AccountReceivablesTable;
use App\Filament\Clusters\Financial\Resources\Components\AccountSummary;
use App\Services\AccountPayable\AccountPayableService;
use App\Services\AccountReceivable\AccountReceivableService;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

abstract class FinancialTitleResource extends Resource
{
    public static string $module;

    public static function service(): AccountPayableService|AccountReceivableService
    {
        return app(static::$module === 'account_payables' ? AccountPayableService::class : AccountReceivableService::class);
    }

    public static function form(Schema $schema): Schema
    {
        return static::$module === 'account_payables'
            ? AccountPayableForm::configure($schema)
            : AccountReceivableForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AccountSummary::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()->id)
            ->with(static::$module === 'account_payables' ? 'supplier' : 'customer')
            ->withGlobalScope('title_payment_flags', fn (Builder $query) => $query->withExists('payments'));
    }

    public static function table(Table $table): Table
    {
        $table = static::$module === 'account_payables'
            ? AccountPayablesTable::configure($table)
            : AccountReceivablesTable::configure($table);
        foreach ($table->getColumns() as $column) {
            $column->toggleable(isToggledHiddenByDefault: ! in_array($column->getName(), ['counterparty_label', 'document_number', 'next_due_date', 'open_balance', 'due_amount', 'status'], true));
        }

        return $table->recordActions([
            ActionGroup::make([
                ViewAction::make()->label('Abrir')->authorize(fn ($livewire, $record) => $livewire->canOperateTitle('view', $record)),
                EditAction::make()->label('Editar')->authorize(fn ($livewire, $record) => $livewire->canOperateTitle('update', $record) && ! $record->payments_exists),
                FinancialTitleActions::ignore(),
                FinancialTitleActions::ignore(true),
                FinancialTitleActions::delete(static::class),
            ])->label('Ações')->icon('heroicon-o-ellipsis-vertical'),
        ])->toolbarActions([])->bulkActions([FinancialTitleActions::deleteBulk(static::class)])
            ->recordUrl(fn ($record) => static::getUrl('view', ['record' => $record]))->defaultSort('due_date')->defaultPaginationPageOption(15)->paginated([15, 30, 50]);
    }
}
