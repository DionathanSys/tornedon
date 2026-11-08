<?php

namespace App\Filament\Clusters\Sales\Resources\FiscalDocuments\Pages;

use App\Filament\Clusters\Sales\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Support\Actions\FiscalDocumentRecordActions;
use App\Models\FiscalDocument;
use App\Notification\NotifyService as notify;
use App\Services\FiscalDocument\FiscalDocumentService;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditFiscalDocument extends EditRecord
{
    protected static string $resource = FiscalDocumentResource::class;

    protected string $view = 'filament.clusters.sales.resources.fiscal-documents.pages.edit-fiscal-document';

    private const REALTIME_REFRESH_STATE_PATHS = [
        'status', 'nfe_status', 'nfse_status', 'document_number', 'document_series',
        'rps_number', 'rps_series', 'document_key', 'errors_messages', 'nfe_payload',
        'emission_requested_at', 'confirmed_at', 'confirmed_by', 'authorized_at',
        'canceled_at', 'updated_at',
    ];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        $data['freight_data'] = $record->freight_data;
        $data['payment_data'] = $record->payment_data;
        $data['tax_data'] = $record->tax_data;
        $data['nfe_payload'] = $record->nfe_payload;
        $data['nfse_payload'] = $record->nfse_payload;
        $data['fiscal_payload_preview'] = $this->payloadJson($record);

        $purchaseInfo = $this->parseAdditionalPurchaseInformation($data['additional_purchase_information'] ?? null);
        $data['additional_purchase_information_nota_empenho'] = $purchaseInfo['nota_empenho'] ?? null;
        $data['additional_purchase_information_pedido'] = $purchaseInfo['pedido'] ?? null;
        $data['additional_purchase_information_contrato'] = $purchaseInfo['contrato'] ?? null;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['additional_purchase_information'] = $this->buildAdditionalPurchaseInformation($data);
        unset($data['additional_purchase_information_nota_empenho'], $data['additional_purchase_information_pedido'], $data['additional_purchase_information_contrato']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = app(FiscalDocumentService::class);
        $updated = $service->update($record, $data, (int) Auth::id());
        if ($service->hasError() || $updated === null) {
            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
            $this->halt();
        }

        return $updated;
    }

    public function getAutoRefreshInterval(): ?string
    {
        return $this->isAutoRefreshEnabled() ? '10s' : null;
    }

    public function isAutoRefreshEnabled(): bool
    {
        $record = $this->getRecord();

        return $record->isNfse()
            ? $record->isNfseQueued() || $record->isNfseInProcessing()
            : $record->isNfeQueued() || $record->isNfeInProcessing();
    }

    public function refreshFiscalDocumentState(): void
    {
        $this->getRecord()->refresh();
        $this->refreshFormData(self::REALTIME_REFRESH_STATE_PATHS);
    }

    protected function getHeaderActions(): array
    {
        return [ActionGroup::make(FiscalDocumentRecordActions::make(includeManagementActions: true))->button()];
    }

    private function payloadJson(FiscalDocument $record): string
    {
        $payload = $record->isNfse() ? $record->nfse_payload : $record->nfe_payload;

        return is_array($payload) && $payload !== []
            ? (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}')
            : '{}';
    }

    private function buildAdditionalPurchaseInformation(array $data): ?string
    {
        $payload = [
            'nota_empenho' => trim((string) ($data['additional_purchase_information_nota_empenho'] ?? '')),
            'pedido' => trim((string) ($data['additional_purchase_information_pedido'] ?? '')),
            'contrato' => trim((string) ($data['additional_purchase_information_contrato'] ?? '')),
        ];
        $payload = array_filter($payload, fn (string $value): bool => $value !== '');

        return $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function parseAdditionalPurchaseInformation(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
