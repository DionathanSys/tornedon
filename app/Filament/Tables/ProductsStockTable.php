<?php

namespace App\Filament\Tables;

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
                ->whereHas('product', fn(Builder $query) => $query->where('is_invoiceable', true)))
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable(),
                TextColumn::make('quantity_available')
                    ->label('Qtde. Disp.')
                    ->numeric(2, ',', '.')
                    ->formatStateUsing(fn($state) => $state == 0 ? 'Esgotado' : number_format($state, 2, ',', '.') . ' un.')
                    ->badge(fn($state) => $state == 0 ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('quantity_reserved')
                    ->label('Qtde. Res.')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->numeric(2, ',', '.')
                    ->sortable(),
                TextColumn::make('average_cost')
                    ->label('Custo Médio')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->money('BRL', 100)
                    ->sortable(),
                TextColumn::make('total_cost')
                    ->label('Custo Total')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->money('BRL', 100)
                    ->sortable(),
                TextColumn::make('last_cost')
                    ->label('Últ. Custo')
                    ->money('BRL', 100)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('last_sale_price')
                    ->label('Últ. Venda (R$)')
                    ->money('BRL', 100)
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
