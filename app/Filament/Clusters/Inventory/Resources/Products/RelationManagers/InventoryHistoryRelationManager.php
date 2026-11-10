<?php

namespace App\Filament\Clusters\Inventory\Resources\Products\RelationManagers;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

class InventoryHistoryRelationManager extends InventoryRelationManager
{
    protected static string $relationship = 'inventoryHistory';

    protected static ?string $title = 'Histórico de gestão';

    protected static string $permission = 'products.view';

    #[On('product-inventory-updated')]
    public function refreshHistory(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => $query->where('company_id', Filament::getTenant()->id)
            ->whereIn('event', ['product.inventory.updated', 'product.costs.updated', 'product.pricing.updated']))
            ->columns([
                TextColumn::make('occurred_at')->label('Data')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('summary')->label('Alteração'),
                TextColumn::make('actor_name')->label('Responsável'),
                TextColumn::make('metadata.reason')->label('Justificativa')->wrap(),
            ])->recordActions([
                Action::make('details')->label('Detalhes')->modalSubmitAction(false)->schema([
                    TextEntry::make('changes')->label('Valores anteriores e novos')->state(function ($record): string {
                        $labels = ['average_cost' => 'Custo médio', 'last_cost' => 'Último custo', 'allow_negative' => 'Permite negativo',
                            'quantity_minimum' => 'Estoque mínimo', 'quantity_maximum' => 'Estoque máximo', 'profit_margin' => 'Acréscimo (%)',
                            'origin_sale_price' => 'Método de preço', 'min_sale_price' => 'Preço mínimo', 'sale_price_value' => 'Preço fixo', 'has_stock_control' => 'Controla estoque'];

                        return collect($record->diff ?? [])->map(fn ($change, $field) => ($labels[$field] ?? $field).': '.json_encode($change['before'], JSON_UNESCAPED_UNICODE).' → '.json_encode($change['after'], JSON_UNESCAPED_UNICODE))->implode("\n");
                    })->prose(),
                ]),
            ])->defaultSort('occurred_at', 'desc');
    }
}
