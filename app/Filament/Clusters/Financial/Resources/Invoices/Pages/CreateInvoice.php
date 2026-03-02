<?php

namespace App\Filament\Clusters\Financial\Resources\Invoices\Pages;

use App\Filament\Clusters\Financial\Resources\Invoices\InvoiceResource;
use App\Notification\NotifyService as notify;
use App\Services\Invoice\InvoiceService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        $data['company_id'] = $tenant->id;

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Fatura criada com sucesso';
    }

    protected function handleRecordCreation(array $data): Model
    {
        Log::debug('CreateInvoice: Iniciando criação de fatura', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'data'   => $data,
        ]);

        $service = app(InvoiceService::class);
        $invoice = $service->create($data, Auth::id());

        if ($service->hasError() || $invoice === null) {
            Log::error($service->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $service->getMessage(),
                'error_code' => $service->getErrorCode(),
                'errors'     => $service->getErrors(),
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );

            $this->halt();
        }

        Log::info('CreateInvoice: Fatura criada com sucesso', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'invoice_id' => $invoice->id,
        ]);

        return $invoice;
    }
}
