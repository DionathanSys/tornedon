<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions\CreateItemAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions\CreateServiceAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions\DeleteItemAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions\EditItemAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('service_id')
            ->heading('Serviços')
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('service.service_code')
                    ->label('Código')
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('service.name')
                    ->label('Serviço')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('quantity')
                    ->label('Quantidade')
                    ->width('1%')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('unit_price')
                    ->label('Valor Unitário')
                    ->width('1%')
                    ->money('BRL', true)
                    // ->visibleFrom('lg')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('gross_amount')
                    ->label('Valor Bruto')
                    ->state(fn ($record): float => round(((float) ($record->quantity ?? 0)) * ((float) ($record->unit_price ?? 0)), 2))
                    ->money('BRL', true)
                    ->width('1%')
                    ->summarize(
                        Sum::make('gross_amount')
                            ->label('Bruto')
                            ->using(fn ($query): float => (float) $query->sum(DB::raw('quantity * unit_price')))
                            ->money('BRL', 100)
                    )
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('total_amount')
                    ->label('Valor Líquido')
                    ->width('1%')
                    ->money('BRL', true)
                    ->summarize(Sum::make('total_amount')->label('Líquido')->money('BRL', 100))
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('discount_percentage')
                    ->label('Des. (%)')
                    ->width('1%')
                    ->visibleFrom('lg')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('discount_amount')
                    ->label('Des. (R$)')
                    ->money('BRL', true)
                    ->summarize(Sum::make('discount_amount')->label('Desc.')->money('BRL', 100))
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('observations')
                    ->label('Observações')
                    ->placeholder('Sem observações')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')
                    ->label('Criado por')
                    ->width('1%')
                    ->sortable()
                    ->visibleFrom('lg')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updatedBy.name')
                    ->label('Atualizado por')
                    ->width('1%')
                    ->sortable()
                    ->visibleFrom('lg')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->width('1%')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->visibleFrom('lg')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->visibleFrom('lg')
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
                CreateItemAction::make(),
                CreateServiceAction::make(),
            ])
            ->emptyStateDescription('Adicione serviços à ordem de serviço para que eles sejam exibidos aqui.');
    }
}
