<?php

namespace App\Filament\Clusters\Inventory\Resources\Products\RelationManagers;

use App\Enum\StockMovement\Type;
use App\Filament\Clusters\Inventory\Resources\Products\Actions\ProductInventoryActions;
use App\Filament\Clusters\Inventory\Resources\StockMovements\Tables\StockMovementsTable;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
use Malzariey\FilamentDaterangepickerFilter\Filters\DateRangeFilter;

class StockMovementsRelationManager extends InventoryRelationManager
{
    protected static string $relationship = 'stockMovements';

    protected static ?string $title = 'Movimentações';

    #[On('product-inventory-updated')]
    public function refreshInventory(): void
    {
        $this->getOwnerRecord()->refresh();
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => $query->where('company_id', Filament::getTenant()->id))
            ->columns([
                TextColumn::make('created_at')->label('Data')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('type')->label('Tipo')->formatStateUsing(fn (Type $state): string => $state->label())
                    ->color(fn (Type $state): string => $state->color())->badge(),
                TextColumn::make('operational_quantity')->label('Qtd. operação')->numeric(decimalPlaces: 3),
                TextColumn::make('operational_unit')->label('Unidade'),
                TextColumn::make('base_quantity')->label('Qtd. estoque')->numeric(decimalPlaces: 3),
                TextColumn::make('base_unit')->label('Unid. estoque'),
                TextColumn::make('unit_price')->label('Custo unitário')->money('BRL', decimalPlaces: 4),
                TextColumn::make('total_amount')->label('Total')->money('BRL'),
                TextColumn::make('source_type')->label('Origem')->formatStateUsing(fn ($state) => match ($state) {
                    'manual' => 'Manual', 'requisition_item', 'requisition' => 'Requisição',
                    'fiscal_document' => 'Nota fiscal', 'production_order' => 'Produção', 'service_order' => 'Ordem de serviço',
                    'quote' => 'Orçamento', default => $state,
                }),
                TextColumn::make('source_id')->label('Documento')->placeholder('—'),
                TextColumn::make('reason')->label('Motivo')->searchable()->wrap(),
                TextColumn::make('createdBy.name')->label('Responsável')->toggleable(),
            ])->filters([
                SelectFilter::make('type')->label('Tipo')->options(collect(Type::cases())->mapWithKeys(fn (Type $type) => [$type->value => $type->label()])->all()),
                SelectFilter::make('source_type')->label('Origem')->options(['manual' => 'Manual', 'requisition_item' => 'Requisição', 'fiscal_document' => 'Nota fiscal', 'production_order' => 'Produção', 'service_order' => 'Ordem de serviço']),
                DateRangeFilter::make('created_at')->label('Período'),
            ])->headerActions(ProductInventoryActions::make($this->getOwnerRecord()))
            ->recordUrl(fn ($record) => StockMovementsTable::resolveRecordUrl($record))
            ->openRecordUrlInNewTab()->defaultSort('id', 'desc');
    }
}
