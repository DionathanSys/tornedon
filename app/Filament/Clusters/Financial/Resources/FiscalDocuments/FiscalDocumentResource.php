<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments;

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

class FiscalDocumentResource extends Resource
{
    protected static ?string $model = FiscalDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $cluster = FinancialCluster::class;

    protected static ?string $modelLabel = 'Documento Fiscal';

    protected static ?string $pluralModelLabel = 'Documentos Fiscais';

    protected static ?int $navigationSort = 4;

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
            'index' => ListFiscalDocuments::route('/'),
            'create' => CreateFiscalDocument::route('/create'),
            'edit' => EditFiscalDocument::route('/{record}/edit'),
        ];
    }
}
