<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments;

use App\Enum\FiscalDocument\OperationType;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\CreateFiscalDocument;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\EditFiscalDocument;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Schemas\FiscalDocumentForm;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\Tables\FiscalDocumentsTable;
use App\Filament\Clusters\Sales\SalesCluster;
use App\Models\FiscalDocument;
use BackedEnum;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class FiscalDocumentResource extends Resource
{
    protected static ?string $model = FiscalDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentText;

    // protected static ?string $cluster = SalesCluster::class;

    protected static string | UnitEnum | null $navigationGroup = 'Vendas';

    protected static ?string $modelLabel = 'Documento Fiscal';

    protected static ?string $pluralModelLabel = 'Documentos Fiscais';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return FiscalDocumentForm::configure($schema);
    }

    /**
     * Restringe o resource apenas a notas de entrada (operation_type = ENTRADA).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('operation_type', OperationType::SAIDA->value);
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
