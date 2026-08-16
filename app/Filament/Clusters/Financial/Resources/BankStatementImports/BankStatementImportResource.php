<?php

namespace App\Filament\Clusters\Financial\Resources\BankStatementImports;

use App\Filament\Clusters\Financial\FinancialCluster;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\Pages\ListBankStatementImports;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\Pages\ViewBankStatementImport;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\RelationManagers\LinesRelationManager;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\Schemas\BankStatementImportInfolist;
use App\Filament\Clusters\Financial\Resources\BankStatementImports\Tables\BankStatementImportsTable;
use App\Models\BankStatementImport;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class BankStatementImportResource extends Resource
{
    protected static ?string $model = BankStatementImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowDownTray;

    // protected static ?string $cluster = FinancialCluster::class;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Importação OFX';

    protected static ?string $pluralModelLabel = 'Importações OFX';

    protected static ?int $navigationSort = 6;

    public static function infolist(Schema $schema): Schema
    {
        return BankStatementImportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankStatementImportsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
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
            'index' => ListBankStatementImports::route('/'),
            'view' => ViewBankStatementImport::route('/{record}'),
        ];
    }
}
