<?php

namespace App\Filament\Shop\Resources\AccountReceivables;

use App\Filament\Clusters\Financial\Resources\AccountReceivables\Schemas\AccountReceivableForm;
use App\Filament\Shop\Resources\AccountReceivables\Pages\CreateAccountReceivable;
use App\Filament\Shop\Resources\AccountReceivables\Pages\EditAccountReceivable;
use App\Filament\Shop\Resources\AccountReceivables\Pages\ListAccountReceivables;
use App\Filament\Shop\Resources\AccountReceivables\Tables\AccountReceivablesTable;
use App\Models\AccountReceivable;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AccountReceivableResource extends Resource
{
    protected static ?string $model = AccountReceivable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Conta à Receber';

    protected static ?string $pluralModelLabel = 'Contas à Receber';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return AccountReceivableForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountReceivablesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountReceivables::route('/'),
            'create' => CreateAccountReceivable::route('/create'),
            'edit' => EditAccountReceivable::route('/{record}/edit'),
        ];
    }
}
