<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments;

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

class FiscalDocumentResource extends Resource
{
    protected static ?string $model = FiscalDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentText;

    protected static ?string $cluster = SalesCluster::class;

    protected static ?string $modelLabel = 'Documento Fiscal';

    protected static ?string $pluralModelLabel = 'Documentos Fiscais';

    protected static ?int $navigationSort = 5;

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
