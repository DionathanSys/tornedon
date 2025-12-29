<?php

namespace App\Filament\Clusters\Partners\Resources\Partners\Pages;

use App\Filament\Clusters\Partners\Resources\Partners\PartnerResource;
use App\Notification\Traits\NotifyTrait;
use App\Services\Partner\PartnerService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use App\Notification\NotifyService as notify;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditPartner extends EditRecord
{
    protected static string $resource = PartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = Auth::id();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = new PartnerService();
        $result = $service->editPartner($data);

        if($service->hasError()){
            notify::error();
            $this->halt();
        }

        notify::success();

        return $result;
    }
}
