<?php

namespace App\Filament\Shop\Resources\CashMovements\Tables;

use App\Enum\Financial\CashMovementDirection;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CashMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_date')
                    ->label('Data')
                    ->date('d/m')
                    ->sortable(),
                TextColumn::make('direction')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->description() ?? '-')
                    ->color(fn ($state) => $state?->color() ?? 'gray'),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->color(fn ($state, $record): string => $record->direction === CashMovementDirection::OUTFLOW ? 'danger' : 'info'),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->limit(32),
            ])
            ->filters([
                SelectFilter::make('direction')
                    ->label('Direção')
                    ->options(CashMovementDirection::toSelectArray())
                    ->native(false),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Movimento Manual'),
            ])
            ->defaultSort('transaction_date', 'desc');
    }
}
