<?php

namespace App\Filament\Clusters\Financial\Resources\PurchaseClosings;

use App\Filament\Clusters\Financial\Resources\PurchaseClosings\Pages\CreatePurchaseClosing;
use App\Filament\Clusters\Financial\Resources\PurchaseClosings\Pages\EditPurchaseClosing;
use App\Filament\Clusters\Financial\Resources\PurchaseClosings\Pages\ListPurchaseClosings;
use App\Filament\Clusters\Financial\Resources\PurchaseClosings\Schemas\PurchaseClosingForm;
use App\Filament\Clusters\Financial\Resources\PurchaseClosings\Tables\PurchaseClosingsTable;
use App\Models\PurchaseClosing;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PurchaseClosingResource extends Resource
{
    protected static ?string $model = PurchaseClosing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Fechamento de Compra';

    protected static ?string $pluralModelLabel = 'Fechamentos de Compra';

    protected static ?int $navigationSort = 7;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id)
            ->with(['supplier', 'accountPayable']);
    }

    public static function form(Schema $schema): Schema
    {
        return PurchaseClosingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseClosingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseClosings::route('/'),
            'create' => CreatePurchaseClosing::route('/create'),
            'edit' => EditPurchaseClosing::route('/{record}/edit'),
        ];
    }
}
