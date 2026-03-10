<?php

namespace App\Services\Fiscal\Actions;

use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use App\Models\FiscalDocument;
use App\Models\FiscalProfile;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class PersistFiscalSnapshotAction
{
    use HandlesActionResponse;

    /**
     * Persiste o snapshot fiscal imutável nos itens do documento e referencia o perfil fiscal no header.
     *
     * @param  FiscalDocument  $document
     * @param  array<int, FiscalDecisionDTO>  $decisions Indexado por item_number
     */
    public function execute(FiscalDocument $document, array $decisions): bool
    {
        try {
            // Gravar referência do perfil fiscal no header do documento
            $profile = FiscalProfile::where('company_id', $document->company_id)
                ->where('is_active', true)
                ->first();

            if ($profile) {
                $document->update([
                    'fiscal_profile_id' => $profile->id,
                    'tax_regime_used' => $profile->tax_regime->value,
                ]);
            }

            // Gravar snapshot + tax_data em cada item
            $document->loadMissing('items');

            foreach ($document->items as $item) {
                $decision = $decisions[$item->item_number] ?? null;

                if ($decision === null) {
                    continue;
                }

                $baseCalculo = (float) $item->total_price;
                $taxData = $decision->toTaxData($baseCalculo);

                $updateData = [
                    'fiscal_snapshot' => $decision->toSnapshotArray(),
                    'tax_data' => $taxData,
                ];

                // Atualizar CFOP se a decisão fiscal determinou um
                if ($decision->cfop !== null) {
                    $updateData['cfop_code'] = $decision->cfop;
                }

                $item->update($updateData);
            }

            $this->setSuccess();

            return true;
        } catch (\Exception $e) {
            Log::error('PersistFiscalSnapshotAction: Erro ao persistir snapshot fiscal', [
                'fiscal_document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            $this->setError('Erro ao persistir snapshot fiscal: ' . $e->getMessage());

            return false;
        }
    }
}
