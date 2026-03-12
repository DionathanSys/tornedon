<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages;

use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\Actions\InvoiceProductionOrderAction;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\Actions\DownloadProductionOrderPdfAction;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\Actions\PreviewProductionOrderPdfAction;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\ProductionOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditProductionOrder extends EditRecord
{
    protected static string $resource = ProductionOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InvoiceProductionOrderAction::make(),
            PreviewProductionOrderPdfAction::make(),
            DownloadProductionOrderPdfAction::make(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = Auth::id();
        
        return $data;
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
