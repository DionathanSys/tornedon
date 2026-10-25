<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Bpo\Resources\CashMovements\Pages;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CashMovementResource extends \App\Filament\Clusters\Financial\Resources\CashMovements\CashMovementResource
{
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('transaction_date')->label('Data')->date('d/m/Y')->sortable(),
            TextColumn::make('description')->label('Descrição')->searchable()->wrap(),
            TextColumn::make('financialAccount.name')->label('Conta')->searchable(),
            TextColumn::make('direction')->label('Tipo')->badge()->formatStateUsing(fn ($state) => $state?->value === 'inflow' ? 'Entrada' : 'Saída')->color(fn ($state) => $state?->value === 'inflow' ? 'success' : 'danger'),
            TextColumn::make('amount')->label('Valor')->money('BRL'),
            TextColumn::make('financialCategory.name')->label('Categoria')->placeholder('—'),
        ])->recordActions([EditAction::make()->visible(fn ($record) => $record->origin_type === 'manual' && ! $record->isTransfer())])
            ->recordUrl(null)->defaultSort('transaction_date', 'desc')->paginated([15, 30, 50]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListMovements::route('/'), 'create' => Pages\CreateMovement::route('/create'), 'edit' => Pages\EditMovement::route('/{record}/edit')];
    }
}
