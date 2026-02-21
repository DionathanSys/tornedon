<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes;

use App\Filament\Clusters\Sales\Resources\Quotes\Pages\CreateQuote;
use App\Filament\Clusters\Sales\Resources\Quotes\Pages\EditQuote;
use App\Filament\Clusters\Sales\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Clusters\Sales\Resources\Quotes\Pages\ViewQuote;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\QuoteForm;
use App\Filament\Clusters\Sales\Resources\Quotes\Tables\QuotesTable;
use App\Filament\Clusters\Sales\SalesCluster;
use App\Models\Quote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentText;

    protected static ?string $cluster = SalesCluster::class;

    protected static ?string $modelLabel = 'Orçamento';

    protected static ?string $pluralModelLabel = 'Orçamentos';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return QuoteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QuotesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotes::route('/'),
            'create' => CreateQuote::route('/create'),
            'view' => ViewQuote::route('/{record}'),
            'edit' => EditQuote::route('/{record}/edit'),
        ];
    }
}
