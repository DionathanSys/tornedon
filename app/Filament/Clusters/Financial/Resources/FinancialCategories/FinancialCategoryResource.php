<?php

namespace App\Filament\Clusters\Financial\Resources\FinancialCategories;

use App\Filament\Clusters\Financial\FinancialCluster;
use App\Filament\Clusters\Financial\Resources\FinancialCategories\Pages\CreateFinancialCategory;
use App\Filament\Clusters\Financial\Resources\FinancialCategories\Pages\EditFinancialCategory;
use App\Filament\Clusters\Financial\Resources\FinancialCategories\Pages\ListFinancialCategories;
use App\Filament\Clusters\Financial\Resources\FinancialCategories\Schemas\FinancialCategoryForm;
use App\Filament\Clusters\Financial\Resources\FinancialCategories\Tables\FinancialCategoriesTable;
use App\Models\FinancialCategory;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FinancialCategoryResource extends Resource
{
    protected static ?string $model = FinancialCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Tag;

    protected static ?string $cluster = FinancialCluster::class;

    protected static ?string $modelLabel = 'Categoria Financeira';

    protected static ?string $pluralModelLabel = 'Categorias Financeiras';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return FinancialCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FinancialCategoriesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinancialCategories::route('/'),
            'create' => CreateFinancialCategory::route('/create'),
            'edit' => EditFinancialCategory::route('/{record}/edit'),
        ];
    }
}
