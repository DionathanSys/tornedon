<?php

namespace App\Filament\Clusters\Partners\Resources\Partners\Pages;

use App\Filament\Clusters\Partners\Resources\Partners\PartnerResource;
use App\Services\Partner\PartnerService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class CreatePartner extends CreateRecord
{
    protected static string $resource = PartnerResource::class;

    protected function handleRecordCreation(array $data): Model
    {   
        Log::debug(__METHOD__, $data);
        $service = app(PartnerService::class);
        $result = $service->registerPartner($data);

        return static::getModel()::create($data);
    }
}
