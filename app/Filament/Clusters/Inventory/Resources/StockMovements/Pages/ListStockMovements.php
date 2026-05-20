<?php

namespace App\Filament\Clusters\Inventory\Resources\StockMovements\Pages;

use App\Filament\Clusters\Inventory\Resources\StockMovements\Pages\Actions\DownloadKardexPdfAction;
use App\Filament\Clusters\Inventory\Resources\StockMovements\StockMovementResource;
use Filament\Resources\Pages\ListRecords;

class ListStockMovements extends ListRecords
{
    protected static string $resource = StockMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DownloadKardexPdfAction::make(),
        ];
    }
}
