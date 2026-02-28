<?php

namespace App\Filament\Clusters\Inventory\Resources\StockMovements\Tables;

use App\Enum\StockMovement\Type;
use App\Filament\Clusters\Inventory\Resources\StockMovements\Actions\Bulk\CheckProductStockBulkAction;
use App\Filament\Clusters\Inventory\Resources\StockMovements\Actions\Bulk\FixProductStockBulkAction;
use App\Filament\Clusters\Inventory\Resources\StockMovements\Actions\CreateStockMovementFromModalAction;
use App\Models\StockMovement;
use Filament\Actions\BulkActionGroup;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\DateRangeFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class StockMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn(): Builder => StockMovement::query()
                ->where('company_id', Filament::getTenant()->id))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo de Mov.')
                    ->formatStateUsing(fn($state) => $state->label())
                    ->color(fn($state) => $state->color())
                    ->badge()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('Qtde.')
                    ->numeric(3, ',', '.')
                    ->formatStateUsing(fn($state) => number_format($state, 3, ',', '.') . ' un.')
                    ->sortable(),
                TextColumn::make('unit_price')
                    ->label('Custo Un.')
                    ->money('BRL', 100)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_amount')
                    ->label('Custo Total')
                    ->money('BRL', 100)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reason')
                    ->label('Motivo')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reference_type')
                    ->label('Referência')
                    ->formatStateUsing(fn($state) => $state ? ucfirst($state) : '-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('observations')
                    ->label('Observações')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')
                    ->label('Criado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo de Mov.')
                    ->options(collect(Type::cases())->mapWithKeys(fn($type) => [$type->value => $type->label()])->toArray()),
                // DateRangeFilter::make('created_at')
                //     ->label('Período de Movimentação'),
            ])
            ->toolbarActions([
                CreateStockMovementFromModalAction::make(),
                BulkActionGroup::make([
                    CheckProductStockBulkAction::make(),
                    FixProductStockBulkAction::make(),
                ])->label('Estoque'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
