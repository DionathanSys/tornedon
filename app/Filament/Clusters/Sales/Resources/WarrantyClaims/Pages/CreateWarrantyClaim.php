<?php

namespace App\Filament\Clusters\Sales\Resources\WarrantyClaims\Pages;

use App\Filament\Clusters\Sales\Resources\WarrantyClaims\Schemas\WarrantyClaimForm;
use App\Filament\Clusters\Sales\Resources\WarrantyClaims\WarrantyClaimResource;
use App\Models\WarrantyClaim;
use App\Notification\NotifyService as notify;
use App\Services\WarrantyClaim\WarrantyClaimService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CreateWarrantyClaim extends CreateRecord
{
    protected static string $resource = WarrantyClaimResource::class;

    public function form(Schema $schema): Schema
    {
        return WarrantyClaimForm::configure($schema);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Filament::getTenant()->id;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $service = app(WarrantyClaimService::class);
        $claim = $service->create($data, Auth::id());

        if ($service->hasError() || ! $claim instanceof WarrantyClaim) {
            Log::error('CreateWarrantyClaim: erro ao criar garantia', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'message' => $service->getMessage(),
                'errors' => $service->getErrors(),
                'error_code' => $service->getErrorCode(),
            ]);

            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());

            throw new \Exception($service->getMessage());
        }

        return $claim;
    }
}
