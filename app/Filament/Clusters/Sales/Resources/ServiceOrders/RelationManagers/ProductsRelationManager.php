<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions\CreateProductAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions\DeleteProductAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions\EditProductAction;
use App\Models\ServiceOrder;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Attributes\On;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'requisitionItems';

    #[On('refresh-products')]
    public function refreshProducts(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product_id')
            ->heading('Produtos')
            ->description(function (): ?string {
                /** @var ServiceOrder $serviceOrder */
                $serviceOrder = $this->getOwnerRecord();
                $requisition = $serviceOrder->requisition;

                if ($requisition === null) {
                    return 'Ao adicionar o primeiro produto, o sistema criará automaticamente uma requisição vinculada a esta OS.';
                }

                return "Produtos vinculados à requisição # {$requisition->number}.";
            })
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('product.product_code')
                    ->label('Código')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable(),
                TextColumn::make('unit_of_measure')
                    ->label('Un.')
                    ->searchable(),
                TextColumn::make('quantity')
                    ->label('Qtde.')
                    ->numeric(3, ',', '.')
                    ->sortable()
                    ->summarize(Sum::make('quantity')->label('TT Qtde.')),
                TextColumn::make('unit_price')
                    ->label('Vlr. Unitário')
                    ->money('BRL', true)
                    ->sortable(),
                TextColumn::make('discount_percentage')
                    ->label('Desc. (%)')
                    ->numeric(2, ',', '.')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('discount_amount')
                    ->label('Desc. (R$)')
                    ->money('BRL', true)
                    ->sortable()
                    ->summarize(Sum::make('discount_amount')->label('TT Desconto')->money('BRL', 100)),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('BRL', true)
                    ->sortable()
                    ->summarize(Sum::make('total_amount')->label('TT Total')->money('BRL', 100)),
                IconColumn::make('stock_consumed')
                    ->label('Estoque Consumido')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('observations')
                    ->label('Observações')
                    ->placeholder('Sem observações')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
            ])
            ->recordActions([
                EditProductAction::make(),
                DeleteProductAction::make(),
            ])
            ->toolbarActions([
                CreateProductAction::make(),
            ])
            ->emptyStateDescription('Adicione produtos para gerar e alimentar a requisição vinculada a esta ordem de serviço.');
    }
}
