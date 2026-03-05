<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Tables;

use App\Enum\Requisition\Status;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RequisitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Número')
                    ->searchable()
                    ->sortable()
                    ->icon(Heroicon::Hashtag),
                TextColumn::make('customer.partner.name')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                TextColumn::make('sale_date')
                    ->label('Data da Venda')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->sortable()
                    ->badge()
                    ->formatStateUsing(fn($state) => $state?->description() ?? '-')
                    ->color(fn($state) => $state?->color() ?? 'gray'),
                TextColumn::make('salesperson.name')
                    ->label('Vendedor')
                    ->sortable()
                    ->limit(30)
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('equipment.name')
                    ->label('Equipamento')
                    ->sortable()
                    ->limit(30)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('delivery_date')
                    ->label('Entrega')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('items_count')
                    ->label('Itens')
                    ->counts('items')
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Status::toSelectArray())
                    ->multiple()
                    ->default([Status::OPEN->value])
                    ->native(false),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton(),
                DeleteAction::make()
                    ->iconButton(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Requisição')
                    ->icon(Heroicon::Plus)
                    ->size(Size::Small),
            ]);
    }
}
