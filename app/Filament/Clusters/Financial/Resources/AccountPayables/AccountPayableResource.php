<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayables;

use App\Filament\Clusters\Financial\FinancialCluster;
use App\Filament\Clusters\Financial\Resources\AccountPayables\Pages\CreateAccountPayable;
use App\Filament\Clusters\Financial\Resources\AccountPayables\Pages\EditAccountPayable;
use App\Filament\Clusters\Financial\Resources\AccountPayables\Pages\ListAccountPayables;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\InstallmentsRelationManager;
use App\Filament\Clusters\Financial\Resources\AccountPayables\RelationManagers\PaymentsRelationManager;
use App\Filament\Clusters\Financial\Resources\AccountPayables\Schemas\AccountPayableForm;
use App\Filament\Clusters\Financial\Resources\AccountPayables\Tables\AccountPayablesTable;
use App\Models\AccountPayable;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AccountPayableResource extends Resource
{
    protected static ?string $model = AccountPayable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowTrendingDown;

    protected static bool $shouldRegisterNavigation = false;

    // protected static ?string $cluster = FinancialCluster::class;

    protected static string | UnitEnum | null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Conta à Pagar';

    protected static ?string $pluralModelLabel = 'Contas à Pagar';

    protected static ?int $navigationSort = 8;

    public static function form(Schema $schema): Schema
    {
        return AccountPayableForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountPayablesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationGroup::make('Parcelas e Pagamentos', [
                InstallmentsRelationManager::class,
                PaymentsRelationManager::class,
            ])
        ];
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
