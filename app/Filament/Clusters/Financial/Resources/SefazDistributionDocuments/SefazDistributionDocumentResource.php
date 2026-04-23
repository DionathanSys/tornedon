<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments;

use App\Filament\Clusters\Financial\FinancialCluster;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Pages\ListSefazDistributionDocuments;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Pages\ViewSefazDistributionDocument;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Schemas\SefazDistributionDocumentInfolist;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Tables\SefazDistributionDocumentsTable;
use App\Models\SefazDistributionDocument;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SefazDistributionDocumentResource extends Resource
{
    protected static ?string $model = SefazDistributionDocument::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $cluster = FinancialCluster::class;

    protected static ?string $modelLabel = 'DF-e detectado';

    protected static ?string $pluralModelLabel = 'DF-e detectados';

    protected static ?string $navigationLabel = 'DF-e Detectados';

    protected static ?int $navigationSort = 10;

    public static function infolist(Schema $schema): Schema
    {
        return SefazDistributionDocumentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SefazDistributionDocumentsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'partner',
                'fiscalDocument',
                'ignoredBy',
                'auditEntries' => fn ($query) => $query->latest('occurred_at'),
            ])
            ->where('company_id', Filament::getTenant()->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSefazDistributionDocuments::route('/'),
            'view' => ViewSefazDistributionDocument::route('/{record}'),
        ];
    }
}
