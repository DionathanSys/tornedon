<?php

namespace App\Domain\DTO\Fiscal;

readonly class ScenarioContext
{
    public function __construct(
        public ?string $referenceType = null,
        public ?string $referenceDocumentKey = null,
        public ?int    $referenceFiscalDocumentId = null,
        public array   $flags = [],
    ) {}

    public function hasReference(): bool
    {
        return $this->referenceDocumentKey !== null;
    }

    public function toArray(): array
    {
        return array_filter([
            'reference_type'               => $this->referenceType,
            'reference_document_key'       => $this->referenceDocumentKey,
            'reference_fiscal_document_id' => $this->referenceFiscalDocumentId,
            'flags'                        => $this->flags ?: null,
        ], fn ($v) => $v !== null);
    }
}
