<?php

namespace App\Filament\Clusters\Inventory\Resources\ProductTaxes\Tables;

use App\Enum\Product\Origin;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductTaxesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->icon(Heroicon::Cube),
                TextColumn::make('product_origin')
                    ->label('Origem')
                    ->sortable()
                    ->placeholder('-')
                    ->formatStateUsing(fn($state) => $state?->description() ?? '-'),
                TextColumn::make('ncm_code')
                    ->label('NCM')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->icon(Heroicon::Hashtag),
                TextColumn::make('cest_code')
                    ->label('CEST')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->icon(Heroicon::Hashtag),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('product_origin')
                    ->label('Origem')
                    ->options(Origin::toSelectArray())
                    ->multiple()
                    ->native(false),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton(),
                DeleteAction::make()
                    ->iconButton(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
