<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages;

use App\Enum\ProductionOrder\Status;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\ProductionOrderResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateProductionOrder extends CreateRecord
{
    protected static string $resource = ProductionOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()->id;
        $data['created_by'] = Auth::id();
        $data['status'] = Status::QUEUED->value;
        
        return $data;
    }
}
