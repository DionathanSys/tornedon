<?php

namespace App\Domain\DTO\Fiscal;

readonly class FiscalDocumentReferenceData
{
    /**
     * @param  array<int|string,mixed>  $raw
     */
    public function __construct(
        public string $referenceType,
        public ?int $fiscalDocumentId = null,
        public ?string $documentKey = null,
        public array $raw = [],
    ) {
    }
}
