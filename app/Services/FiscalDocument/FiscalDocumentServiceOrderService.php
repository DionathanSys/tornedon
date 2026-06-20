<?php

namespace App\Services\FiscalDocument;

use App\Enum\FiscalDocument\OperationType;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Models\FiscalDocument;
use App\Models\RemittanceAsset;
use App\Models\ServiceOrder;
use App\Services\ServiceOrder\ServiceOrderService;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FiscalDocumentServiceOrderService
{
    use HandlesServiceResponse;

    public function createFromEntryDocument(FiscalDocument $document, array $data, int $userId): ?ServiceOrder
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($document, $data, $userId): ?ServiceOrder {
                if ($document->operation_type !== OperationType::ENTRADA) {
                    $this->setError('A ordem de serviço só pode ser criada a partir de uma nota de entrada.');

                    return null;
                }

                $assetIds = collect($data['remittance_asset_ids'] ?? [])
                    ->filter()
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique()
                    ->values();

                if ($assetIds->isEmpty()) {
                    $this->setError('Selecione ao menos um item preparado para criar a ordem de serviço.');

                    return null;
                }

                $primaryAssetId = (int) ($data['primary_remittance_asset_id'] ?? 0);

                $assets = RemittanceAsset::query()
                    ->with(['equipment', 'fiscalDocumentItem'])
                    ->where('company_id', $document->company_id)
                    ->where('fiscal_document_id', $document->id)
                    ->whereIn('id', $assetIds)
                    ->whereNotNull('equipment_id')
                    ->get();

                if ($assets->count() !== $assetIds->count()) {
                    $this->setError('Um ou mais itens selecionados não possuem equipamento vinculado.');

                    return null;
                }

                $primaryAsset = $assets->firstWhere('id', $primaryAssetId);

                if (! $primaryAsset instanceof RemittanceAsset || $primaryAsset->equipment_id === null) {
                    $this->setError('Selecione um equipamento principal válido para a ordem de serviço.');

                    return null;
                }

                $serviceOrderService = app(ServiceOrderService::class);
                $serviceOrder = $serviceOrderService->create([
                    'company_id' => $document->company_id,
                    'customer_id' => $document->customer_id,
                    'equipment_id' => $primaryAsset->equipment_id,
                    'order_date' => $data['order_date'] ?? now()->toDateString(),
                    'status' => $data['status'] ?? State::OPEN->value,
                    'priority' => $data['priority'] ?? Priority::NORMAL->value,
                    'type' => $data['type'] ?? Type::MAINTENANCE->value,
                    'customer_observations' => $data['customer_observations'] ?? null,
                    'general_observations' => $data['general_observations'] ?? null,
                    'items_received' => $data['items_received'] ?? $this->buildItemsReceivedSummary($assets),
                ], $userId);

                if ($serviceOrderService->hasError() || ! $serviceOrder instanceof ServiceOrder) {
                    $this->setError($serviceOrderService->getMessageUser());

                    return null;
                }

                $serviceOrder->remittanceAssets()->syncWithoutDetaching(
                    $assets->mapWithKeys(fn (RemittanceAsset $asset): array => [
                        $asset->id => [
                            'quantity_allocated' => (float) $asset->received_quantity,
                            'notes' => 'Vinculado a partir da nota de entrada #'.($document->document_number ?: $document->id),
                        ],
                    ])->all()
                );

                $this->setSuccess('Ordem de serviço criada com sucesso.');

                Log::info('FiscalDocumentServiceOrderService: ordem de serviço criada a partir da nota', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'fiscal_document_id' => $document->id,
                    'service_order_id' => $serviceOrder->id,
                    'remittance_asset_ids' => $assets->pluck('id')->all(),
                    'user_id' => $userId,
                ]);

                return $serviceOrder;
            });
        } catch (\Throwable $e) {
            $this->setError('Erro ao criar ordem de serviço a partir da nota de entrada.');

            Log::error('FiscalDocumentServiceOrderService: exceção ao criar ordem de serviço', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'fiscal_document_id' => $document->id,
                'user_id' => $userId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    private function buildItemsReceivedSummary(Collection $assets): string
    {
        return $assets
            ->map(function (RemittanceAsset $asset): string {
                $description = $asset->equipment?->name
                    ?? $asset->fiscalDocumentItem?->description
                    ?? ('Item '.$asset->fiscal_document_item_id);

                return sprintf(
                    '%s (Qtde.: %s)',
                    $description,
                    number_format((float) $asset->received_quantity, 4, ',', '.')
                );
            })
            ->implode("\n");
    }
}
