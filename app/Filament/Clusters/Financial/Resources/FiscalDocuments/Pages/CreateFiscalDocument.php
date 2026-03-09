<?php

namespace App\Filament\Clusters\Financial\Resources\FiscalDocuments\Pages;

use App\Filament\Clusters\Financial\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Notification\NotifyService as notify;
use App\Services\Fiscal\Actions\PersistFiscalSnapshotAction;
use App\Services\Fiscal\Actions\ResolveFiscalContextAction;
use App\Services\FiscalDocument\FiscalDocumentService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CreateFiscalDocument extends CreateRecord
{
    protected static string $resource = FiscalDocumentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();
        $data['company_id'] = $tenant->id;

        return $data;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Documento fiscal criado com sucesso';
    }

    protected function handleRecordCreation(array $data): Model
    {
        Log::debug('CreateFiscalDocument: Iniciando criação de documento fiscal', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'data'   => $data,
        ]);

        $service = app(FiscalDocumentService::class);
        $fiscalDocument = $service->create($data, Auth::id());

        if ($service->hasError() || $fiscalDocument === null) {
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

        Log::info('CreateFiscalDocument: Documento fiscal criado com sucesso', [
            'metodo'             => __METHOD__ . '@' . __LINE__,
            'fiscal_document_id' => $fiscalDocument->id,
        ]);

        return $fiscalDocument;
    }

    /**
     * Dispara a resolução fiscal logo após a criação, quando os itens já estão persistidos.
     */
    protected function afterCreate(): void
    {
        Log::debug('CreateFiscalDocument: Iniciando resolução fiscal pós-criação', [
            'metodo' => __METHOD__ . '@' . __LINE__,
        ]);
        
        $document = $this->getRecord();
        $document->loadMissing('items');

        if ($document->items->isEmpty()) {
            return;
        }

        try {
            $decisions = app(ResolveFiscalContextAction::class)
                ->execute($document, $document->items->all());

            if (! empty($decisions)) {
                (new PersistFiscalSnapshotAction())->execute($document, $decisions);
            }
        } catch (\Exception $e) {
            Log::error('CreateFiscalDocument: Erro ao resolver contexto fiscal', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $document->id,
                'error'              => $e->getMessage(),
            ]);

            notify::error(message: 'Documento criado, mas houve um erro ao calcular os impostos: ' . $e->getMessage());
        }
    }
}
