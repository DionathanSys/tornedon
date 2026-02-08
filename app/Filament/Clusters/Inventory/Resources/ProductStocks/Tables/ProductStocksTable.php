<?php

namespace App\Filament\Clusters\Inventory\Resources\ProductStocks\Tables;

use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductStocksTable
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
                TextColumn::make('quantity_available')
                    ->label('Qtd. Disponível')
                    ->numeric(decimalPlaces: 3)
                    ->sortable()
                    ->alignEnd()
                    ->color(fn($record) => $record->quantity_available <= $record->quantity_minimum ? 'danger' : 'success'),
                TextColumn::make('quantity_reserved')
                    ->label('Qtd. Reservada')
                    ->numeric(decimalPlaces: 3)
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('quantity_minimum')
                    ->label('Est. Mínimo')
                    ->numeric(decimalPlaces: 3)
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('average_cost')
                    ->label('Custo Médio')
                    ->money('BRL')
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('last_movement_date')
                    ->label('Último Movimento')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('last_movement_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn(?string $state) => match ($state) {
                        'entrada' => 'Entrada',
                        'saida' => 'Saída',
                        'ajuste' => 'Ajuste',
                        'transferencia' => 'Transferência',
                        default => '-',
                    })
                    ->color(fn(?string $state) => match ($state) {
                        'entrada' => 'success',
                        'saida' => 'danger',
                        'ajuste' => 'warning',
                        'transferencia' => 'info',
                        default => 'gray',
                    })
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean()
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(),
                IconColumn::make('allow_negative')
                    ->label('Permite Negativo')
                    ->boolean()
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Ativo')
                    ->boolean()
                    ->trueLabel('Apenas ativos')
                    ->falseLabel('Apenas inativos')
                    ->native(false),
                TernaryFilter::make('low_stock')
                    ->label('Estoque Baixo')
                    ->queries(
                        true: fn($query) => $query->whereColumn('quantity_available', '<=', 'quantity_minimum'),
                        false: fn($query) => $query->whereColumn('quantity_available', '>', 'quantity_minimum'),
                    )
                    ->trueLabel('Apenas estoque baixo')
                    ->falseLabel('Apenas estoque normal')
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
