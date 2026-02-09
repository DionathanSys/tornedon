<?php

namespace App\Filament\Clusters\Sales\Resources\Requisitions\Pages;

use App\Filament\Clusters\Sales\Resources\Requisitions\RequisitionResource;
use App\Services\Requisition\RequisitionService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateRequisition extends CreateRecord
{
    protected static string $resource = RequisitionResource::class;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $tenant = Filament::getTenant();
        $items = $data['items'] ?? [];
        unset($data['items']);

        $service = app(RequisitionService::class);

        return $service->create($data, $items, Auth::id(), $tenant->id);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
