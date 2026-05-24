<?php

namespace App\Services\FiscalDocument;

use App\Enum\FiscalDocument\OperationType;
use App\Models\Equipment;
use App\Models\FiscalDocumentItem;
use App\Models\RemittanceAsset;
use App\Services\Equipment\EquipmentService;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FiscalDocumentRemittanceAssetService
{
    use HandlesServiceResponse;

    public function saveForItem(FiscalDocumentItem $item, array $data, int $userId): ?RemittanceAsset
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($item, $data, $userId): ?RemittanceAsset {
                $item->loadMissing('fiscalDocument', 'remittanceAsset');

                $document = $item->fiscalDocument;

                if ($document === null || $document->operation_type !== OperationType::ENTRADA) {
                    $this->setError('Somente notas de entrada permitem vincular equipamentos aos itens.');

                    return null;
                }

                $mode = $data['mode'] ?? 'existing';
                $equipment = $mode === 'new'
                    ? $this->createEquipmentFromData($document->company_id, $document->customer_id, $data, $userId)
                    : $this->resolveExistingEquipment($document->company_id, $document->customer_id, (int) ($data['equipment_id'] ?? 0));

                if (! $equipment instanceof Equipment) {
                    return null;
                }

                $asset = $item->remittanceAsset ?? new RemittanceAsset;

                $asset->fill([
                    'company_id' => $document->company_id,
                    'fiscal_document_id' => $document->id,
                    'fiscal_document_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'equipment_id' => $equipment->id,
                    'serial_number' => $data['asset_serial_number'] ?? $equipment->serial_number,
                    'lot_number' => $data['lot_number'] ?? null,
                    'received_quantity' => $data['received_quantity'] ?? $item->quantity,
                    'status' => 'received',
                    'metadata' => array_filter([
                        'equipment_mode' => $mode,
                        'equipment_name' => $equipment->name,
                        'fiscal_document_item_number' => $item->item_number,
                    ]),
                    'updated_by' => $userId,
                ]);

                $asset->created_by ??= $userId;
                $asset->save();

                $this->setSuccess('Equipamento vinculado ao item com sucesso.');

                Log::info('FiscalDocumentRemittanceAssetService: vínculo salvo com sucesso', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'fiscal_document_item_id' => $item->id,
                    'remittance_asset_id' => $asset->id,
                    'equipment_id' => $equipment->id,
                    'mode' => $mode,
                    'user_id' => $userId,
                ]);

                return $asset;
            });
        } catch (\Throwable $e) {
            $this->setError('Erro ao vincular equipamento ao item da nota.');

            Log::error('FiscalDocumentRemittanceAssetService: exceção ao salvar vínculo', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_item_id' => $item->id,
                'user_id' => $userId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    private function createEquipmentFromData(int $companyId, int $ownerId, array $data, int $userId): ?Equipment
    {
        $payload = [
            'company_id' => $companyId,
            'owner_id' => $ownerId,
            'name' => $data['equipment_name'] ?? null,
            'type' => $data['equipment_type'] ?? null,
            'placa' => $data['placa'] ?? null,
            'mark' => $data['mark'] ?? null,
            'model' => $data['model'] ?? null,
            'serial_number' => $data['equipment_serial_number'] ?? null,
            'created_by' => $userId,
        ];

        $equipmentService = app(EquipmentService::class);
        $equipment = $equipmentService->create($payload);

        if ($equipmentService->hasError() || ! $equipment instanceof Equipment) {
            $this->setError($equipmentService->getMessageUser());

            return null;
        }

        return $equipment;
    }

    private function resolveExistingEquipment(int $companyId, int $ownerId, int $equipmentId): ?Equipment
    {
        $equipment = Equipment::query()
            ->where('company_id', $companyId)
            ->where('owner_id', $ownerId)
            ->find($equipmentId);

        if (! $equipment instanceof Equipment) {
            $this->setError('O equipamento selecionado não pertence ao cliente desta nota.');

            return null;
        }

        return $equipment;
    }
}
