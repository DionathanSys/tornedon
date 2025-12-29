<?php

namespace App\Filament\Clusters\Partners\Resources\Partners\Pages;

use App\Filament\Clusters\Partners\Resources\Partners\PartnerResource;
use App\Models\User;
use App\Notification\NotifyService as notify;
use App\Services\Partner\PartnerService;
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
        $data['company_id'] = User::find(Auth::id())
            ->companies()
            ->first()
            ->id;

        $data['type'] = $data['companies'][0]['type'];
        unset($data['companies']);

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Parceiro registrado';
    }

    protected function handleRecordCreation(array $data): Model
    {

        Log::debug(__METHOD__ . '-' . __LINE__, [
            'data' => $data,
        ]);

        ds($data)->label(__METHOD__.'@'.__LINE__);

        $service = new PartnerService();
        $result = $service->registerPartner($data);

        if($service->hasError()){
            notify::error();
            $this->halt();
        }

        return $result;
    }
}
