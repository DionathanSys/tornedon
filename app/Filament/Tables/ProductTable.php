<?php

namespace App\Filament\Tables;

use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Product::query())
            ->columns([
                TextColumn::make('product_code')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('description')
                    ->searchable(),
                TextColumn::make('category.name')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->boolean(),
                IconColumn::make('is_custom_manufacturing')
                    ->boolean(),
                IconColumn::make('has_stock_control')
                    ->boolean(),
                TextColumn::make('unit')
                    ->badge()
                    ->searchable(),
                TextColumn::make('profit_margin')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('min_sale_price')
                    ->money()
                    ->sortable(),
                TextColumn::make('origin_sale_price')
                    ->badge()
                    ->searchable(),
                TextColumn::make('sale_price_value')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('item_type')
                    ->searchable(),
                TextColumn::make('manufacturer_code')
                    ->searchable(),
                TextColumn::make('gross_weight')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('net_weight')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('barcode')
                    ->searchable(),
                TextColumn::make('created_by')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('updated_by')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('company.name')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    //
                ]),
            ]);
    }
}
