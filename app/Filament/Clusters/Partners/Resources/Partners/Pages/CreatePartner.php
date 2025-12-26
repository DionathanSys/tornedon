<?php

namespace App\Filament\Clusters\Partners\Resources\Partners\Pages;

use App\Filament\Clusters\Partners\Resources\Partners\PartnerResource;
use App\Services\Partner\PartnerService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CreatePartner extends CreateRecord
{
    protected static string $resource = PartnerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        ds($data)->label('Creating partner with data in ' . __METHOD__ . '@' . __LINE__);

        $service = app(PartnerService::class);
        $result = $service->registerPartner($data);

        if($service->hasError()) {
            ds('Erro encontrado em service');
            Notification::make()
                ->title('Erro ao criar parceiro')
                ->danger()
                ->body(implode("\n", $service->getErrors()))
                ->send();
            $this->halt();
        }

        return $result;
    }
}
