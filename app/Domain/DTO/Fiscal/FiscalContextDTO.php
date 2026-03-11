<?php

namespace App\Domain\DTO\Fiscal;

use App\Enum\FiscalDocument\OperationNature;
use App\Enum\Tax\FiscalOperationType;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
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
    ) {
    }

    public static function fromFiscalDocumentItem(
        FiscalDocument $document,
        FiscalDocumentItem $item,
    ): self {
        $company            = $document->company;
        $customer           = $document->customer;
        $companyAddress     = $company->address ?? [];
        $customerAddress    = $customer?->addresses?->first();

        $operationNature = $document->operation_nature instanceof OperationNature
            ? $document->operation_nature->value
            : $document->operation_nature;

        $operationType = $document->operation_type;
        $isOutbound = $operationType instanceof \App\Enum\FiscalDocument\OperationType
            ? $operationType === \App\Enum\FiscalDocument\OperationType::SAIDA
            : (string) $operationType === '1';

        return new self(
            companyId:              $document->company_id,
            documentType:           $document->document_type ?? 'nfe',
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
        ];
    }
}
