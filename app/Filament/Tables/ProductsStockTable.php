<?php

namespace App\Filament\Tables;

use App\Enum\Product\Unit;
use App\Enum\StockMovement\Type;
use App\Models\ProductStock;
use Filament\Actions\BulkActionGroup;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ProductsStockTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn(): Builder => ProductStock::query()
                ->where('company_id', Filament::getTenant()->id)
                ->whereHas('product', fn(Builder $query) => $query->where('is_invoiceable', true)->where('is_active', true)))
            ->columns([
                TextColumn::make('product.product_code')
                    ->label('Código')
                    ->searchable(),
                TextColumn::make('product.name')
                    ->label('Nome')
                    ->searchable(),
                TextColumn::make('quantity_available')
                    ->label('Qtde. Disp.')
                    ->numeric(2, ',', '.')
                    ->formatStateUsing(fn($state) => $state == 0 ? 'Esgotado' : number_format($state, 2, ',', '.'))
                    ->badge(fn($state) => $state == 0 ? 'danger' : null)
                    ->sortable(),
                    TextColumn::make('product.unit')
                    ->label('Un.')
                    ->formatStateUsing(fn(Unit $state) => $state ?? '-')
                    ->tooltip(fn(Unit $state) => $state?->description())
                    ->searchable(),
                TextColumn::make('quantity_reserved')
                    ->label('Qtde. Res.')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->numeric(2, ',', '.')
                    ->sortable(),
                TextColumn::make('average_cost')
                    ->label('Custo Médio')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('total_cost')
                    ->label('Custo Total')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('last_cost')
                    ->label('Últ. Custo')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('last_sale_price')
                    ->label('Últ. Venda (R$)')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('last_movement_date')
                    ->label('Últ. Mov.')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('last_movement_type')
                    ->label('Tipo Últ. Mov.')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn(Type $state) => $state->label ?? '-')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Criado Em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado Em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->label('Excluído Em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->disabledSelection()
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
