<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables;

use App\Filament\Clusters\Financial\FinancialCluster;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\Pages\CreateAccountReceivable;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\Pages\EditAccountReceivable;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\Pages\ListAccountReceivables;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\InstallmentsRelationManager;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\RelationManagers\PaymentsRelationManager;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\Schemas\AccountReceivableForm;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\Tables\AccountReceivablesTable;
use App\Models\AccountReceivable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AccountReceivableResource extends Resource
{
    protected static ?string $model = AccountReceivable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowTrendingUp;

    protected static ?string $cluster = FinancialCluster::class;

    protected static ?string $modelLabel = 'Conta à Receber';

    protected static ?string $pluralModelLabel = 'Contas à Receber';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return AccountReceivableForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountReceivablesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
        ];
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
