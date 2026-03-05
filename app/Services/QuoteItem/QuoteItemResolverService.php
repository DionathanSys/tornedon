<?php

namespace App\Services\QuoteItem;

use App\Domain\DTO\Quote\QuoteItemSourceDTO;
use App\Enum\Quote\Destination;
use App\Services\Product\ProductSalePriceService;
use App\Services\Product\ProductService;
use App\Services\ProductStock\ProductStockService;
use App\Services\Service\ServiceService;

class QuoteItemResolverService
{
    public function __construct(
        protected ProductService $productService,
        protected ProductSalePriceService $productSalePriceService,
        protected ServiceService $serviceService,
        protected ProductStockService $productStockService,
    ) {
    }

    public function resolveForStock(int $productStockId): ?QuoteItemSourceDTO
    {
        $stock = $this->productStockService->find($productStockId);

        if (!$stock || !$stock->product) {
            return null;
        }

        $product = $stock->product;
        $price = $this->productSalePriceService->resolve($product, $stock);

        return new QuoteItemSourceDTO(
            productStockId: $stock->id,
            productId: $product->id,
            serviceId: null,
            code: $product->product_code,
            name: $product->name,
            unit: $product->unit?->value,
            price: (float) $price,
            minSalePrice: $this->productSalePriceService->getMinSalePriceById($product->id),
            destination: Destination::REQUISITION
        );
    }

    public function resolveForProduct(int $productId): ?QuoteItemSourceDTO
    {
        $product = $this->productService->find($productId);

        if (!$product) {
            return null;
        }

        $price = $this->productSalePriceService->resolve($product);

        return new QuoteItemSourceDTO(
            productStockId: null,
            productId: $product->id,
            serviceId: null,
            code: $product->product_code,
            name: $product->name,
            unit: $product->unit?->value,
            price: (float) $price,
            minSalePrice: $this->productSalePriceService->getMinSalePriceById($product->id),
            destination: Destination::ORDER_PRODUCTION
        );
    }

    public function resolveForService(int $serviceId): ?QuoteItemSourceDTO
    {
        $service = $this->serviceService->find($serviceId);

        if (!$service) {
            return null;
        }

        return new QuoteItemSourceDTO(
            serviceId: $service->id,
            productStockId: null,
            productId: null,
            code: $service->service_code,
            name: $service->name,
            unit: $service->unit_of_measure,
            price: (float) $service->price,
            minSalePrice: (float) $service->min_sale_price,
            destination: Destination::ORDER_SERVICE
        );
    }
}
