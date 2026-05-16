<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers;

use App\Enum\Requisition\Status;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions\CreateProductAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions\DeleteProductAction;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Actions\EditProductAction;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\Requisition\RequisitionService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;
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
                    return '';
                }

                return "Requisição # {$requisition->number} - Status: {$requisition->status->description()}";
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
                Action::make('delete-linked-requisition')
                    ->label('Excluir Requisição')
                    ->icon(Heroicon::Trash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Excluir requisição vinculada')
                    ->modalDescription('Tem certeza que deseja excluir a requisição vinculada a esta ordem de serviço?')
                    ->modalSubmitActionLabel('Sim, excluir')
                    ->visible(function (): bool {
                        /** @var ServiceOrder $serviceOrder */
                        $serviceOrder = $this->getOwnerRecord();
                        $requisition = $serviceOrder->requisition;

                        return $requisition !== null
                            && $requisition->status === Status::OPEN;
                    })
                    ->action(function (): void {
                        /** @var ServiceOrder $serviceOrder */
                        $serviceOrder = $this->getOwnerRecord();
                        $requisition = $serviceOrder->requisition;

                        if ($requisition === null) {
                            return;
                        }

                        Log::debug('ProductsRelationManager: excluindo requisição vinculada', [
                            'metodo' => __METHOD__ . '@' . __LINE__,
                            'service_order_id' => $serviceOrder->id,
                            'requisition_id' => $requisition->id,
                        ]);

                        $service = app(RequisitionService::class);
                        $result = $service->delete($requisition);

                        if (! $result || $service->hasError()) {
                            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

                            return;
                        }

                        notify::success(message: 'Requisição vinculada excluída com sucesso.');

                        $this->dispatch('refresh-page');
                        $this->dispatch('refresh-products');
                    }),
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
