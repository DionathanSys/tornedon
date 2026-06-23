<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages;

use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status;
use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource;
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
        $data['additional_purchase_information'] = $this->buildAdditionalPurchaseInformation($data);
        $data['company_id'] = Filament::getTenant()->id;
        $data['status'] = Status::PENDING->value;
        $data['operation_type'] = OperationType::SAIDA->value;

        if (($data['operation_nature'] ?? null) === OperationNature::REMESSA_GARANTIA->value) {
            data_set($data, 'tax_data.reference.type', data_get($data, 'tax_data.reference.type', 'warranty_remittance'));
        }

        unset(
            $data['additional_purchase_information_nota_empenho'],
            $data['additional_purchase_information_pedido'],
            $data['additional_purchase_information_contrato'],
        );

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $service = app(FiscalDocumentService::class);
        $document = $service->create($data, Auth::id());

        if ($service->hasError() || $document === null) {
            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode()
            );
            $this->halt();
        }

        return $document;
    }

    /**
     * Dispara a resolução fiscal logo após a criação, quando os itens já estão persistidos.
     */
    protected function afterCreate(): void
    {
        Log::debug('CreateFiscalDocument: Iniciando resolução fiscal pós-criação', [
            'metodo' => __METHOD__.'@'.__LINE__,
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
                (new PersistFiscalSnapshotAction)->execute($document, $decisions);
            }
        } catch (\Exception $e) {
            Log::error('CreateFiscalDocument (Sales): Erro ao resolver contexto fiscal', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            notify::error(message: 'Documento criado, mas houve um erro ao calcular os impostos: '.$e->getMessage());
        }
    }

    private function buildAdditionalPurchaseInformation(array $data): ?string
    {
        $payload = [
            'nota_empenho' => trim((string) ($data['additional_purchase_information_nota_empenho'] ?? '')),
            'pedido' => trim((string) ($data['additional_purchase_information_pedido'] ?? '')),
            'contrato' => trim((string) ($data['additional_purchase_information_contrato'] ?? '')),
        ];

        $payload = array_filter($payload, fn (string $value): bool => $value !== '');

        if ($payload === []) {
            return null;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
