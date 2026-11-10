<?php

namespace App\Filament\Clusters\Inventory\Resources\Products\RelationManagers;

use App\Filament\Clusters\Inventory\Resources\StockMovements\Tables\StockMovementsTable;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class ReservationsRelationManager extends InventoryRelationManager
{
    protected static string $relationship = 'stockMovements';

    protected static ?string $title = 'Reservas';

    #[On('product-inventory-updated')]
    public function refreshReservations(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $openReservations = DB::table('stock_movements')
            ->where('company_id', Filament::getTenant()->id)
            ->where('product_id', $this->getOwnerRecord()->id)
            ->whereIn('type', ['reservation', 'reservation_release'])
            ->selectRaw("MIN(id) as first_movement_id, SUM(CASE WHEN type = 'reservation' THEN quantity ELSE -quantity END) as reserved_quantity")
            ->groupBy('source_type', 'source_id')
            ->havingRaw("SUM(CASE WHEN type = 'reservation' THEN quantity ELSE -quantity END) > 0.0001");

        return $table->modifyQueryUsing(fn (Builder $query) => $query
            ->where('stock_movements.company_id', Filament::getTenant()->id)
            ->joinSub($openReservations, 'open_reservations', 'stock_movements.id', '=', 'open_reservations.first_movement_id')
            ->select('stock_movements.*', 'open_reservations.reserved_quantity'))
            ->description('Reservas em aberto, reconciliadas pelas movimentações. Abra o documento de origem para administrar a reserva.')
            ->columns([
                TextColumn::make('source_type')->label('Origem')->formatStateUsing(fn ($state) => match ($state) {
                    'requisition', 'requisition_item' => 'Requisição', 'manual' => 'Manual', default => $state,
                }),
                TextColumn::make('source_id')->label('Referência'),
                TextColumn::make('reserved_quantity')->label('Quantidade reservada (unidade base)')->numeric(decimalPlaces: 3),
            ])->recordUrl(fn ($record) => StockMovementsTable::resolveRecordUrl($record))->openRecordUrlInNewTab()
            ->defaultSort('id')->emptyStateHeading('Nenhuma reserva em aberto');
    }
}
