<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments;

use App\Filament\Clusters\Financial\FinancialCluster;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Pages\ListSefazDistributionDocuments;
use App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Tables\SefazDistributionDocumentsTable;
use App\Models\SefazDistributionDocument;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
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

    public static function table(Table $table): Table
    {
        return SefazDistributionDocumentsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSefazDistributionDocuments::route('/'),
        ];
    }
}
