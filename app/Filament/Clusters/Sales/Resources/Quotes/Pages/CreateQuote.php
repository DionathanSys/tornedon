<?php

namespace App\Filament\Clusters\Sales\Resources\Quotes\Pages;

use App\Filament\Clusters\Sales\Resources\Quotes\QuoteResource;
use App\Services\Quote\QuoteService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Notification\NotifyService as notify;

class CreateQuote extends CreateRecord
{
    protected static string $resource = QuoteResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        Log::debug('CreateQuote: Mutando dados antes de criar', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'data' => $data,
        ]);

        $tenant = Filament::getTenant();
        $data['company_id'] = $tenant->id;

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Orçamento criado com sucesso';
    }

    protected function handleRecordCreation(array $data): Model
    {
        Log::debug('CreateQuote: Iniciando criação de orçamento', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'data' => $data,
        ]);

        $service = app(QuoteService::class);
        $quote = $service->create($data, Auth::id());

        if ($service->hasError() || $quote === null) {
            Log::error($service->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $service->getMessage(),
                'error_code' => $service->getErrorCode(),
                'errors' => $service->getErrors(),
            ]);

            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );
            
            $this->halt();
        }

        Log::info('CreateQuote: Orçamento criado com sucesso', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'quote_id' => $quote->id,
        ]);

        return $quote;
    }
}
