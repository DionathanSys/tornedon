<?php

namespace App\Domain\DTO\Fiscal;

use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\OperationType as DocumentOperationType;
use App\Enum\Tax\FiscalOperationType;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Product;
use App\Models\Service;
use Carbon\Carbon;

class FiscalContextDTO
{
    public function __construct(
        public readonly int $companyId,
        public readonly string $documentType,
        public readonly FiscalOperationType $operationType,
        public readonly string $movementDirection, // in, out
        public readonly string $issuerUf,
        public readonly ?string $recipientUf,
        public readonly ?string $recipientTaxpayerType, // StateTaxIndicator value
        public readonly bool $recipientFinalConsumer,
        public readonly ?int $productId,
        public readonly ?string $productNcm,
        public readonly ?string $productCest,
        public readonly ?string $productOrigin,
        public readonly ?string $operationNature,
        public readonly Carbon $issuedAt,
        public readonly bool $isCustomManufacturing = false, // produto de fabricação customizada/sob encomenda
        public readonly ?bool $productHasSt = null,
        // NFS-e
        public readonly ?string $nfseModel = null, // municipal, nacional
        public readonly ?int $serviceId = null,
        public readonly ?string $serviceCode = null, // código LC 116/2003
        public readonly ?string $issExigibility = null,
        public readonly ?float $serviceTaxRate = null, // aliquota ISS do cadastro do serviço
    ) {
    }

    public static function fromFiscalDocumentItem(
        FiscalDocument $document,
        FiscalDocumentItem $item,
    ): self {
        $company            = $document->company;
        $customer           = $document->customer;
        $companyAddress     = $company->address ?? [];
        $customerAddress    = $customer?->address?->first();

        $operationNature = $document->operation_nature instanceof OperationNature
            ? $document->operation_nature->value
            : $document->operation_nature;

        $operationType = $document->operation_type;
        $isOutbound = $operationType instanceof DocumentOperationType
            ? $operationType === DocumentOperationType::SAIDA
            : (string) $operationType === '1';

        $documentType = $document->document_type instanceof DocumentModel
            ? $document->document_type->value
            : (string) ($document->document_type ?? 'nfe');

        // Verifica se o produto é de fabricação customizada e tem ST
        $product = $item->product_id ? Product::find($item->product_id) : null;
        $isCustomManufacturing = (bool) ($product?->is_custom_manufacturing ?? false);
        $productHasSt = $product ? (bool) ($product->has_st ?? false) : null;

        return new self(
            companyId:              $document->company_id,
            documentType:           $documentType,
            operationType:          self::resolveOperationType($operationNature),
            movementDirection:      $isOutbound ? 'out' : 'in',
            issuerUf:               $companyAddress['state'] ?? '',
            recipientUf:            $customerAddress?->state ?? null,
            recipientTaxpayerType:  $customer?->state_tax_indicator?->value,
            recipientFinalConsumer: (bool) $document->is_final_consumer,
            productId:              $item->product_id,
            productNcm:             $item->ncm_code,
            productCest:            $item->cest_code,
            productOrigin:          $item->product_origin,
            operationNature:        $operationNature,
            issuedAt:               $document->issued_at ?? now(),
            isCustomManufacturing:  $isCustomManufacturing,
            productHasSt:           $productHasSt,
        );
    }

    /**
     * Cria contexto fiscal a partir de um item NFS-e (serviço).
     */
    public static function fromNfseItem(
        FiscalDocument $document,
        FiscalDocumentItem $item,
    ): self {
        $company         = $document->company;
        $customer        = $document->customer;
        $companyAddress  = $company->address ?? [];
        $customerAddress = $customer?->address?->first();

        $service = $item->service_id ? Service::find($item->service_id) : null;

        return new self(
            companyId:              $document->company_id,
            documentType:           DocumentModel::NFSE->value,
            operationType:          FiscalOperationType::SALE,
            movementDirection:      'out',
            issuerUf:               $companyAddress['state'] ?? '',
            recipientUf:            $customerAddress?->state ?? null,
            recipientTaxpayerType:  $customer?->state_tax_indicator?->value,
            recipientFinalConsumer: true,
            productId:              null,
            productNcm:             null,
            productCest:            null,
            productOrigin:          null,
            operationNature:        null,
            issuedAt:               $document->issued_at ?? now(),
            productHasSt:           null,
            nfseModel:              $document->nfse_model,
            serviceId:              $item->service_id,
            serviceCode:            $service?->service_code,
            issExigibility:         $service?->iss_exigibility?->value ?? $item->iss_exigibility,
            serviceTaxRate:         $service?->tax_rate ? (float) $service->tax_rate : null,
        );
    }

    /**
     * Mapeia OperationNature para um tipo de operação simplificado do motor de regras.
     */
    private static function resolveOperationType(?string $operationNature): FiscalOperationType
    {
        $enum = OperationNature::tryFrom($operationNature ?? '');

        return match ($enum) {
            OperationNature::VENDA_DENTRO_ESTADO,
            OperationNature::VENDA_FORA_ESTADO           => FiscalOperationType::SALE,
            OperationNature::DEVOLUCAO_COMPRA            => FiscalOperationType::RETURN,
            OperationNature::REMESSA_CONSERTO,
            OperationNature::RETORNO_CONSERTO            => FiscalOperationType::REPAIR,
            OperationNature::REMESSA_DEMONSTRACAO,
            OperationNature::RETORNO_DEMONSTRACAO,
            OperationNature::SIMPLES_REMESSA             => FiscalOperationType::REMITTANCE,
            OperationNature::TRANSFERENCIA               => FiscalOperationType::TRANSFER,
            OperationNature::BONIFICACAO                 => FiscalOperationType::BONUS,
            default                                      => FiscalOperationType::SALE,
        };
    }

    public function isInterestadual(): bool
    {
        return $this->recipientUf !== null
            && $this->issuerUf !== ''
            && $this->issuerUf !== $this->recipientUf;
    }

    public function toArray(): array
    {
        return [
            'company_id'                => $this->companyId,
            'document_type'             => $this->documentType,
            'operation_type'            => $this->operationType->value,
            'movement_direction'        => $this->movementDirection,
            'issuer_uf'                 => $this->issuerUf,
            'recipient_uf'              => $this->recipientUf,
            'recipient_taxpayer_type'   => $this->recipientTaxpayerType,
            'recipient_final_consumer'  => $this->recipientFinalConsumer,
            'product_id'                => $this->productId,
            'product_ncm'               => $this->productNcm,
            'product_cest'              => $this->productCest,
            'product_origin'            => $this->productOrigin,
            'operation_nature'          => $this->operationNature,
            'issued_at'                 => $this->issuedAt->toDateString(),
            'is_custom_manufacturing'   => $this->isCustomManufacturing,
            'product_has_st'            => $this->productHasSt,
            'nfse_model'                => $this->nfseModel,
            'service_id'                => $this->serviceId,
            'service_code'              => $this->serviceCode,
            'iss_exigibility'           => $this->issExigibility,
            'service_tax_rate'          => $this->serviceTaxRate,
        ];
    }
}
