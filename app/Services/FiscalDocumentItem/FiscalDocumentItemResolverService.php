<?php

namespace App\Services\FiscalDocumentItem;

use App\Domain\DTO\FiscalDocument\FiscalDocumentItemSourceDTO;
use App\Enum\FiscalDocument\DocumentModel;
use App\Models\Service;
use App\Services\FiscalDocument\NfseDocumentService;
use App\Services\Product\ProductSalePriceService;
use App\Services\Product\ProductService;
use App\Services\ProductStock\ProductStockService;

class FiscalDocumentItemResolverService
{
    public function __construct(
        protected ProductService $productService,   
        protected ProductStockService $productStockService,
        protected ProductSalePriceService $productSalePriceService,
    ) {}

    /**
     * Resolve os dados de um item para NF-e a partir de um ProductStock.
     */
    public function resolveForProduct(int $productId): ?FiscalDocumentItemSourceDTO
    {
        $product = $this->productService->find($productId);
        $stock = $product->stocks()->first();

        if (! $stock && ! $product) {
            return null;
        }

        $price = $this->productSalePriceService->resolve($product);
        return new FiscalDocumentItemSourceDTO(
            type: DocumentModel::NFE,

            // Campos comuns
            code:  $product->product_code,
            name:  $product->name,
            unit:  $product->unit?->value,
            price: (float) $price,

            // NF-e
            productId:      $product->id,   
            productStockId: $stock?->id,
            productCode:    $product->product_code,
            productOrigin:  $product->tax?->product_origin,
            ncmCode:        $product->tax?->ncm_code,
            cestCode:       $product->tax?->cest_code,
            barcode:        $product->barcode,
        );
    }

    /**
     * Resolve os dados de um item para NFS-e a partir de um Service.
     *
     * @param  int $serviceId   ID do serviço selecionado
     * @param  int $companyId   ID da empresa (tenant) para buscar defaults fiscais
     */
    public function resolveForService(int $serviceId, int $companyId): ?FiscalDocumentItemSourceDTO
    {
        $service = Service::find($serviceId);

        if (! $service) {
            return null;
        }

        return new FiscalDocumentItemSourceDTO(
            type: DocumentModel::NFSE,

            // Campos comuns
            code:  $service->service_code,
            name:  $service->name,
            unit:  null,
            price: (float) $service->price,

            // NFS-e
            serviceId:      $service->id,
            serviceCode:    $service->municipal_tax_code ?? NfseDocumentService::getDefaultServiceCode($companyId),
            nbsCode:        $service->nbs_code           ?? NfseDocumentService::getDefaultNbsCode($companyId),
            cnaeCode:       $service->cnae_code          ?? NfseDocumentService::getDefaultCnaeCode($companyId),
            issRate:        $service->tax_rate           ? (float) $service->tax_rate : null,
            issExigibility: $service->iss_exigibility?->value,
        );
    }
}
