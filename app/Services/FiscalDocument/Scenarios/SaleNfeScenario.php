<?php

namespace App\Services\FiscalDocument\Scenarios;

use App\Enum\FiscalDocument\DocumentModel;
use App\Models\FiscalDocument;
use App\Models\NfeSequence;
use App\Services\Fiscal\NfeConfigService;
use App\Services\FiscalDocument\Contracts\FiscalEmissionScenarioInterface;

class SaleNfeScenario implements FiscalEmissionScenarioInterface
{
    public function supports(FiscalDocument $document): bool
    {
        return $document->isNfe();
    }

    public function code(): string
    {
        return 'sale';
    }

    public function documentModel(): string
    {
        return DocumentModel::NFE->value;
    }

    public function channelCode(FiscalDocument $document): string
    {
        return 'nfe';
    }

    public function payloadBuilderKey(FiscalDocument $document): ?string
    {
        return 'nfe:default';
    }

    public function resolveSeries(FiscalDocument $document): string
    {
        $config = app(NfeConfigService::class);

        return trim((string) ($document->document_series ?: $config->resolveSerie((int) $document->company_id)));
    }

    public function resolveOperationNature(FiscalDocument $document): ?string
    {
        return $document->operation_nature?->value ?? $document->operation_nature;
    }

    public function resolveCandidateNumber(FiscalDocument $document, string $series): ?int
    {
        $operationNature = $this->resolveOperationNature($document);

        if (! is_string($operationNature) || trim($operationNature) === '') {
            return null;
        }

        return NfeSequence::peekNextNumber((int) $document->company_id, $series, $operationNature);
    }

    public function buildQueueGroupKey(FiscalDocument $document, string $series, int $environment): string
    {
        return implode(':', [
            $this->documentModel(),
            (int) $document->company_id,
            $series,
            $environment,
        ]);
    }

    public function validate(FiscalDocument $document, array &$errors): void
    {
    }
}
