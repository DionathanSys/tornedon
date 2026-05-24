<?php

namespace App\Services\Fiscal\Sefaz;

use App\Models\Product;
use App\Models\SefazDistributionDocument;
use App\Models\SefazItemMapping;

class SefazItemMappingService
{
    public function findMapping(int $companyId, ?int $partnerId, ?string $xmlItemCode): ?SefazItemMapping
    {
        if ($partnerId === null || ! is_string($xmlItemCode) || trim($xmlItemCode) === '') {
            return null;
        }

        return SefazItemMapping::query()
            ->where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('xml_item_code', trim($xmlItemCode))
            ->first();
    }

    public function findMappedProductId(int $companyId, ?int $partnerId, ?string $xmlItemCode): ?int
    {
        return $this->findMapping($companyId, $partnerId, $xmlItemCode)?->product_id;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function syncMappings(SefazDistributionDocument $document, array $items, ?int $actorUserId = null): void
    {
        if ($document->partner_id === null) {
            return;
        }

        foreach ($items as $item) {
            $xmlItemCode = isset($item['product_code']) ? trim((string) $item['product_code']) : '';
            $productId = $item['product_id'] ?? null;
            $productUnit = isset($item['product_unit']) ? trim((string) $item['product_unit']) : '';

            if ($xmlItemCode === '' || ! is_numeric($productId)) {
                continue;
            }

            $product = Product::query()
                ->where('company_id', $document->company_id)
                ->find((int) $productId);

            if (! $product) {
                continue;
            }

            SefazItemMapping::query()->updateOrCreate(
                [
                    'company_id' => $document->company_id,
                    'partner_id' => $document->partner_id,
                    'xml_item_code' => $xmlItemCode,
                ],
                [
                    'product_id' => $product->id,
                    'product_unit' => $productUnit !== '' ? $productUnit : ($product->unit?->value ?? (string) $product->unit),
                    'xml_barcode' => $item['barcode'] ?? null,
                    'xml_description' => $item['description'] ?? null,
                    'last_used_at' => now(),
                    'created_by' => $actorUserId,
                    'updated_by' => $actorUserId,
                ],
            );
        }
    }
}
