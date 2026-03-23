<?php

namespace App\Services\ServiceDiscount;

use App\Models\CompanyPartner;
use App\Models\Service;

class ServiceDiscountService
{
    public function resolveAutomaticDiscount(
        ?int $companyId,
        ?int $customerId,
        int|Service|null $service,
        float $quantity,
        float $unitPrice,
    ): array {
        $service = $this->resolveService($service);

        if (! $service || ! $companyId || ! $customerId) {
            return $this->emptyDiscount($quantity, $unitPrice, null);
        }

        $requestedPercentage = $this->getCustomerDiscountPercentage($companyId, $customerId, $service);

        if ($requestedPercentage <= 0) {
            return $this->emptyDiscount($quantity, $unitPrice, $service);
        }

        return $this->buildDiscountPayload(
            service: $service,
            quantity: $quantity,
            unitPrice: $unitPrice,
            discountPercentage: $requestedPercentage,
            discountAmount: null,
            clampToMinSalePrice: true,
        );
    }

    public function buildDiscountPayload(
        int|Service|null $service,
        float $quantity,
        float $unitPrice,
        ?float $discountPercentage = null,
        ?float $discountAmount = null,
        bool $clampToMinSalePrice = false,
    ): array {
        $service = $this->resolveService($service);
        $quantity = max(0, $quantity);
        $unitPrice = max(0, $unitPrice);
        $subtotal = round($quantity * $unitPrice, 4);

        if ($discountAmount !== null) {
            $discountAmount = max(0, round($discountAmount, 2));
        } else {
            $discountPercentage = max(0, $discountPercentage ?? 0);
            $discountAmount = round($subtotal * ($discountPercentage / 100), 2);
        }

        if ($clampToMinSalePrice) {
            $discountAmount = min($discountAmount, $this->getMaximumDiscountAmount($service, $quantity, $unitPrice));
        }

        $discountAmount = min($discountAmount, round($subtotal, 2));
        $discountPercentage = $subtotal > 0
            ? round(($discountAmount / $subtotal) * 100, 2)
            : 0.0;

        $totalAmount = round($subtotal - $discountAmount, 2);
        $effectiveUnitPrice = $quantity > 0
            ? round($totalAmount / $quantity, 4)
            : 0.0;

        return [
            'discount_percentage' => $discountPercentage,
            'discount_amount' => round($discountAmount, 2),
            'subtotal' => round($subtotal, 2),
            'total_amount' => $totalAmount,
            'effective_unit_price' => $effectiveUnitPrice,
            'min_sale_price' => $this->getMinSalePrice($service),
            'max_discount_amount' => $this->getMaximumDiscountAmount($service, $quantity, $unitPrice),
            'max_discount_percentage' => $this->getMaximumDiscountPercentage($service, $quantity, $unitPrice),
            'accept_customer_discount' => (bool) ($service?->accept_customer_discount ?? false),
        ];
    }

    public function validateEffectiveUnitPrice(
        int|Service|null $service,
        float $quantity,
        float $unitPrice,
        float $discountAmount,
    ): ?string {
        $service = $this->resolveService($service);
        $minSalePrice = $this->getMinSalePrice($service);

        if (! $service || $minSalePrice === null || $quantity <= 0) {
            return null;
        }

        $totalAfterDiscount = round(($quantity * $unitPrice) - $discountAmount, 2);
        $effectiveUnitPrice = $quantity > 0
            ? round($totalAfterDiscount / $quantity, 4)
            : 0.0;

        if ($effectiveUnitPrice + 0.0001 >= $minSalePrice) {
            return null;
        }

        return sprintf(
            'O preço efetivo do serviço "%s" após desconto (R$ %s) não pode ficar abaixo do preço mínimo de venda (R$ %s).',
            $service->name,
            number_format($effectiveUnitPrice, 2, ',', '.'),
            number_format($minSalePrice, 2, ',', '.')
        );
    }

    public function getCustomerDiscountPercentage(int $companyId, int $customerId, int|Service|null $service): float
    {
        $service = $this->resolveService($service);

        if (! $service || ! $service->accept_customer_discount) {
            return 0.0;
        }

        $companyPartner = CompanyPartner::query()
            ->where('company_id', $companyId)
            ->where('partner_id', $customerId)
            ->where('is_active', true)
            ->first();

        $percentage = (float) ($companyPartner?->customer_discount_percentage ?? 0);

        return $percentage > 0 ? round($percentage, 2) : 0.0;
    }

    public function getMaximumDiscountAmount(int|Service|null $service, float $quantity, float $unitPrice): float
    {
        $minSalePrice = $this->getMinSalePrice($this->resolveService($service));
        $subtotal = round(max(0, $quantity) * max(0, $unitPrice), 4);

        if ($subtotal <= 0) {
            return 0.0;
        }

        if ($minSalePrice === null) {
            return round($subtotal, 2);
        }

        $minimumTotal = round($minSalePrice * max(0, $quantity), 4);

        return round(max(0, $subtotal - $minimumTotal), 2);
    }

    public function getMaximumDiscountPercentage(int|Service|null $service, float $quantity, float $unitPrice): float
    {
        $subtotal = round(max(0, $quantity) * max(0, $unitPrice), 4);

        if ($subtotal <= 0) {
            return 0.0;
        }

        return round(($this->getMaximumDiscountAmount($service, $quantity, $unitPrice) / $subtotal) * 100, 2);
    }

    public function getMinSalePrice(int|Service|null $service): ?float
    {
        $service = $this->resolveService($service);

        if (! $service || $service->min_sale_price === null) {
            return null;
        }

        $minSalePrice = (float) $service->min_sale_price;

        return $minSalePrice > 0 ? round($minSalePrice, 2) : null;
    }

    private function emptyDiscount(float $quantity, float $unitPrice, ?Service $service): array
    {
        return $this->buildDiscountPayload(
            service: $service,
            quantity: $quantity,
            unitPrice: $unitPrice,
            discountPercentage: 0,
            discountAmount: 0,
            clampToMinSalePrice: false,
        );
    }

    private function resolveService(int|Service|null $service): ?Service
    {
        if ($service instanceof Service) {
            return $service;
        }

        if (! $service) {
            return null;
        }

        return Service::find($service);
    }
}
