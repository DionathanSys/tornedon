<?php

namespace App\Filament\Clusters\Inventory\Resources\ProductStocks\Tables;

use App\Notification\NotifyService as notify;
use App\Services\ProductStock\ProductStockService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ProductStocksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->width('1%')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->icon(Heroicon::Cube),
                TextColumn::make('quantity_available')
                    ->label('Qtd. Disponível')
                    ->width('1%')
                    ->numeric(decimalPlaces: 3)
                    ->sortable()
                    ->alignEnd()
                    ->color(fn($record) => $record->quantity_available <= $record->quantity_minimum ? 'danger' : 'success'),
                TextColumn::make('quantity_reserved')
                    ->label('Qtd. Reservada')
                    ->width('1%')
                    ->numeric(decimalPlaces: 3)
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('quantity_minimum')
                    ->label('Est. Mínimo')
                    ->width('1%')
                    ->numeric(decimalPlaces: 3)
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('average_cost')
                    ->label('Custo Médio')
                    ->width('1%')
                    ->money('BRL')
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('last_movement_date')
                    ->label('Últ. Mov.')
                    ->width('1%')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('last_movement_type')
                    ->label('Tipo')
                    ->width('1%')
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
                    ->width('1%')
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
                    ->width('1%')
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
                ViewAction::make()
                    ->iconButton()
            ])
            ->toolbarActions([
                
            ])
            ->defaultSort('created_at', 'desc');
    }
}
