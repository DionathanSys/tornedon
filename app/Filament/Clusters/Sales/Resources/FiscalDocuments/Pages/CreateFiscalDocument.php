<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages;

use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Notification\NotifyService as notify;
use App\Services\Fiscal\Actions\PersistFiscalSnapshotAction;
use App\Services\Fiscal\Actions\ResolveFiscalContextAction;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;

class CreateFiscalDocument extends CreateRecord
{
    protected static string $resource = FiscalDocumentResource::class;

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
            Log::error('CreateFiscalDocument (Sales): Erro ao resolver contexto fiscal', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $document->id,
                'error'              => $e->getMessage(),
            ]);

            notify::error(message: 'Documento criado, mas houve um erro ao calcular os impostos: ' . $e->getMessage());
        }
    }
}
