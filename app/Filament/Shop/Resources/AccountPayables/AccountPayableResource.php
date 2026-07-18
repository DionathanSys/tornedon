<?php

namespace App\Filament\Shop\Resources\AccountPayables;

use App\Filament\Shop\Resources\AccountPayables\Pages\CreateAccountPayable;
use App\Filament\Shop\Resources\AccountPayables\Pages\EditAccountPayable;
use App\Filament\Shop\Resources\AccountPayables\Pages\ListAccountPayables;
use App\Filament\Shop\Resources\AccountPayables\RelationManagers\InstallmentsRelationManager;
use App\Filament\Shop\Resources\AccountPayables\RelationManagers\PaymentsRelationManager;
use App\Filament\Shop\Resources\AccountPayables\Schemas\AccountPayableForm;
use App\Filament\Shop\Resources\AccountPayables\Tables\AccountPayablesTable;
use App\Models\AccountPayable;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AccountPayableResource extends Resource
{
    protected static ?string $model = AccountPayable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowTrendingDown;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Conta à Pagar';

    protected static ?string $pluralModelLabel = 'Contas à Pagar';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return AccountPayableForm::configure($schema, useSections: false);
    }

    public static function table(Table $table): Table
    {
        return AccountPayablesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            InstallmentsRelationManager::class,
            PaymentsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountPayables::route('/'),
            'create' => CreateAccountPayable::route('/create'),
            'edit' => EditAccountPayable::route('/{record}/edit'),
        ];
    }
}
