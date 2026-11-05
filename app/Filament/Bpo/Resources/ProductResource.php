<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Bpo\Resources\Products\Pages;
use App\Services\Access\CompanyAuthorization;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductResource extends \App\Filament\Clusters\Inventory\Resources\Products\ProductResource
{
    public static function getEloquentQuery(): Builder
    {
        return app(CompanyAuthorization::class)->scopeQuery(parent::getEloquentQuery()->where('company_id', Filament::getTenant()->id),
            Filament::auth()->user(), Filament::getTenant(), 'products.view');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('product_code')->label('Código')->searchable(),
            TextColumn::make('name')->label('Produto')->searchable()->sortable(),
            TextColumn::make('unit')->label('Unidade'),
            TextColumn::make('sale_price_value')->label('Preço de venda')->money('BRL'),
            IconColumn::make('is_active')->label('Ativo')->boolean(),
        ])->filters([TernaryFilter::make('is_active')->label('Ativo')])
            ->recordActions([EditAction::make()])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListProducts::route('/'), 'create' => Pages\CreateProduct::route('/create'), 'edit' => Pages\EditProduct::route('/{record}/edit')];
    }
}
