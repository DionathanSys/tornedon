<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers;

use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Filament\Clusters\Sales\Resources\Quotes\Schemas\Components\ModalSelectService;
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
                TextColumn::make('service.service_code')
                    ->label('Código'),
                TextColumn::make('service.name')
                    ->label('Serviço'),
                TextColumn::make('quantity')
                    ->label('Quantidade')
                    ->alignRight(),
                TextColumn::make('unit_price')
                    ->label('Valor Unitário')
                    ->money('BRL', true)    
                    ->alignRight(),
                TextColumn::make('total_amount')
                    ->label('Valor Total')
                    ->money('BRL', true)
                    ->alignRight()
                    ->summarize(Sum::make()),
                TextColumn::make('discount_percentage')
                    ->label('Des. (%)')
                    ->alignRight()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('discount_amount')
                    ->label('Des. (R$)')
                    ->money('BRL', true)
                    ->alignRight()
                    ->toggleable(isToggledHiddenByDefault: false),
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
                
            ])
            ->recordActions([
                EditItemAction::make(),
                DeleteItemAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
                CreateItemAction::make(),
            ]);
    }
}
