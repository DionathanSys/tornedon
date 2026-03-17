<?php

namespace App\Domain\DTO\FiscalDocument;

use App\Enum\FiscalDocument\DocumentModel;

/**
 * DTO que carrega os dados de origem de um item de documento fiscal,
 * seja para NF-e (mercadoria) ou NFS-e (serviço).
 *
 * Os campos são separados por contexto fiscal:
 *  - Comuns: código, nome, unidade, preço
 *  - NF-e:   produto_id, product_origin, ncm_code, cest_code, barcode, cfop_code etc.
 *  - NFS-e:  service_id, service_code, nbs_code, cnae_code, iss_rate, iss_exigibility
 */
readonly class FiscalDocumentItemSourceDTO
{
    public function __construct(
        /** Discrimina o tipo de documento para o qual este DTO foi resolvido */
        public DocumentModel $type,

        /* =====================================================================
         | Campos comuns
         |======================================================================*/

        public ?string $code        = null,
        public ?string $name        = null,
        public ?string $unit        = null,
        public ?float  $price       = null,

        /* =====================================================================
         | NF-e — Mercadoria
         |======================================================================*/

        /** ID do produto (tabela products) */
        public ?int    $productId        = null,

        /** ID do ProductStock (quando selecionado via estoque) */
        public ?int    $productStockId   = null,

        /** Código interno do produto (product_code) */
        public ?string $productCode      = null,

        /** Origem da mercadoria (enum Origin value) */
        public ?string $productOrigin    = null,

        public ?string $ncmCode          = null,
        public ?string $cestCode         = null,
        public ?string $cfopCode         = null,
        public ?string $barcode          = null,

        /* =====================================================================
         | NFS-e — Serviço
         |======================================================================*/

        /** ID do serviço (tabela services) */
        public ?int    $serviceId        = null,

        /** Código de serviço municipal (LC 116) */
        public ?string $serviceCode      = null,

        public ?string $nbsCode          = null,
        public ?string $cnaeCode         = null,

        /** Alíquota do ISS (%) */
        public ?float  $issRate          = null,

        /** Exigibilidade do ISS (enum IssExigibility value) */
        public ?string $issExigibility   = null,
    ) {}
}
