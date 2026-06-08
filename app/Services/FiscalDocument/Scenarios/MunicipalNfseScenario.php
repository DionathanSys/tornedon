<?php

namespace App\Services\FiscalDocument\Scenarios;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfseModel;
use App\Models\FiscalDocument;
use App\Models\NfseSequence;
use App\Services\Fiscal\NfseConfigService;
use App\Services\FiscalDocument\Contracts\FiscalEmissionScenarioInterface;

class MunicipalNfseScenario implements FiscalEmissionScenarioInterface
{
    public function supports(FiscalDocument $document): bool
    {
        return $document->isNfse() && $this->resolveModel($document) === NfseModel::MUNICIPAL->value;
    }

    public function code(): string
    {
        return 'municipal_nfse';
    }

    public function documentModel(): string
    {
        return DocumentModel::NFSE->value;
    }

    public function channelCode(FiscalDocument $document): string
    {
        return 'nfse:municipal';
    }

    public function payloadBuilderKey(FiscalDocument $document): ?string
    {
        return 'municipal';
    }

    public function resolveSeries(FiscalDocument $document): string
    {
        $config = app(NfseConfigService::class);

        return trim((string) ($document->rps_series ?: $config->resolveSerie((int) $document->company_id)));
    }

    public function resolveOperationNature(FiscalDocument $document): ?string
    {
        return null;
    }

    public function resolveCandidateNumber(FiscalDocument $document, string $series): ?int
    {
        $reservedNumber = (int) preg_replace('/\D/', '', (string) ($document->rps_number ?? ''));

        if ($reservedNumber > 0) {
            return $reservedNumber;
        }

        return NfseSequence::peekNextNumber((int) $document->company_id, $series);
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

    public function validate(FiscalDocument $document, array &$errors): void {}

    public function resolveContext(FiscalDocument $document): \App\Domain\DTO\Fiscal\ScenarioContext
    {
        return new \App\Domain\DTO\Fiscal\ScenarioContext;
    }

    private function resolveModel(FiscalDocument $document): string
    {
        return $document->nfse_model instanceof NfseModel
            ? $document->nfse_model->value
            : trim((string) $document->nfse_model);
    }
}
