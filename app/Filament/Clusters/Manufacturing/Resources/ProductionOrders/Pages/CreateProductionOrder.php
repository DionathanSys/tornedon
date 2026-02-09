<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages;

use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\ProductionOrderResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateProductionOrder extends CreateRecord
{
    protected static string $resource = ProductionOrderResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Auth::user()->company_id;
        $data['created_by'] = Auth::id();
        
        return $data;
    }
}
