<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments;

use App\Enum\FiscalDocument\OperationType;
use App\Filament\Clusters\Financial\FinancialCluster;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages\CreateFiscalDocument;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Schemas\FiscalDocumentForm;
use App\Filament\Clusters\Financial\Resources\FiscalDocuments\Tables\FiscalDocumentsTable;
use App\Models\FiscalDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;
use Illuminate\Database\Eloquent\Builder;

class FiscalDocumentResource extends Resource
{
    protected static ?string $model = FiscalDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    // protected static ?string $cluster = FinancialCluster::class;

    protected static string | UnitEnum | null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Nota de Entrada';

    protected static ?string $pluralModelLabel = 'Notas de Entrada';

    protected static ?string $navigationLabel = 'Notas de Entrada';

    protected static ?int $navigationSort = 9;

    /**
     * Restringe o resource apenas a notas de entrada (operation_type = ENTRADA).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('operation_type', OperationType::ENTRADA->value);
    }

    public static function form(Schema $schema): Schema
    {
        return FiscalDocumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FiscalDocumentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListFiscalDocuments::route('/'),
            'create' => CreateFiscalDocument::route('/create'),
            'edit'   => EditFiscalDocument::route('/{record}/edit'),
        ];
    }
}
