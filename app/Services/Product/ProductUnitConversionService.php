<?php

namespace App\Services\Product;

use App\Domain\DTO\Product\UnitConversionResultDTO;
use App\Enum\Product\Unit;
use App\Models\Product;
use App\Models\ProductAlternativeUnit;
use InvalidArgumentException;

class ProductUnitConversionService
{
    public function convertToBase(Product $product, string $unit, float $quantity): UnitConversionResultDTO
    {
        $normalizedUnit = $this->normalizeUnit($unit);
        $baseUnit       = $this->baseUnit($product);
        $factor         = $this->resolveFactor($product, $normalizedUnit);

        return new UnitConversionResultDTO(
            productId: (int) $product->id,
            operationalUnit: $normalizedUnit,
            operationalQuantity: $quantity,
            baseUnit: $baseUnit,
            baseQuantity: round($quantity * $factor, 8),
            factor: $factor,
            displayRule: sprintf('1 %s = %s %s', $normalizedUnit, $this->formatFactor($factor), $baseUnit),
        );
    }

    public function convertFromBase(Product $product, string $unit, float $quantity): UnitConversionResultDTO
    {
        $normalizedUnit = $this->normalizeUnit($unit);
        $baseUnit       = $this->baseUnit($product);
        $factor         = $this->resolveFactor($product, $normalizedUnit);

        if ($factor <= 0.0) {
            throw new InvalidArgumentException('Fator de conversão inválido para a unidade informada.');
        }

        return new UnitConversionResultDTO(
            productId: (int) $product->id,
            operationalUnit: $normalizedUnit,
            operationalQuantity: round($quantity / $factor, 8),
            baseUnit: $baseUnit,
            baseQuantity: $quantity,
            factor: $factor,
            displayRule: sprintf('1 %s = %s %s', $normalizedUnit, $this->formatFactor($factor), $baseUnit),
        );
    }

    public function isAllowedUnit(Product $product, string $unit): bool
    {
        $normalizedUnit = $this->normalizeUnit($unit);

        if ($normalizedUnit === $this->baseUnit($product)) {
            return true;
        }

        $product->loadMissing('alternativeUnitConversions');

        return $product->alternativeUnitConversions
            ->contains(fn(ProductAlternativeUnit $conversion): bool => $conversion->unit?->value === $normalizedUnit);
    }

    public function getAvailableUnits(Product $product): array
    {
        $product->loadMissing('alternativeUnitConversions');

        $units = [$this->baseUnit($product)];

        foreach ($product->alternativeUnitConversions as $conversion) {
            $unit = $conversion->unit?->value;

            if ($unit && !in_array($unit, $units, true)) {
                $units[] = $unit;
            }
        }

        return $units;
    }

    private function resolveFactor(Product $product, string $unit): float
    {
        if ($unit === $this->baseUnit($product)) {
            return 1.0;
        }

        $product->loadMissing('alternativeUnitConversions');

        $conversion = $product->alternativeUnitConversions
            ->first(fn(ProductAlternativeUnit $item): bool => $item->unit?->value === $unit);

        if (!$conversion) {
            throw new InvalidArgumentException(sprintf('A unidade %s não está cadastrada para o produto #%d.', $unit, $product->id));
        }

        $factor = (float) $conversion->conversion_factor;

        if ($factor <= 0.0) {
            throw new InvalidArgumentException(sprintf('A unidade %s possui fator de conversão inválido.', $unit));
        }

        return $factor;
    }

    private function baseUnit(Product $product): string
    {
        $unit = $product->unit;

        return $unit instanceof Unit ? $unit->value : (string) $unit;
    }

    private function normalizeUnit(string $unit): string
    {
        return mb_strtoupper(trim($unit));
    }

    private function formatFactor(float $factor): string
    {
        $formatted = number_format($factor, 8, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
