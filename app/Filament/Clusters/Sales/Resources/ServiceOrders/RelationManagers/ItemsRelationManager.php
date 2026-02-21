<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions\CreateItemAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions\CreateItemActionExample;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions\DeleteItemAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions\EditItemAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('service_id')
            ->heading('Serviços')
            ->columns([
                TextColumn::make('service.name')
                    ->label('Serviço')
                    ->searchable(),
                TextColumn::make('quantity')
                    ->label('Qtde.')
                    ->numeric(2, ',', '.')
                    ->sortable(),
                TextColumn::make('unit_price')
                    ->label('Vlr. Unit.')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('discount_percentage')
                    ->label('Desc. (%)')
                    ->numeric(2, ',', '.')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('discount_amount')
                    ->label('Desc. (R$)')
                    ->money('BRL')
                    ->summarize(Sum::make('discount_amount')->label('TT Desconto')->money('BRL', 100))
                    ->sortable(),
                TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money('BRL')
                    ->summarize(Sum::make('subtotal')->label('TT Subtotal')->money('BRL', 100))
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('BRL')
                    ->summarize(Sum::make('total_amount')->label('TT Geral')->money('BRL', 100))
                    ->sortable(),
                TextColumn::make('createdBy')
                    ->label('Criado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updatedBy')
                    ->label('Atualizado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateItemAction::make(),
            ])
            ->recordActions([
                EditItemAction::make(),
                DeleteItemAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
