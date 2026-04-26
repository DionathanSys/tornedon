<?php

namespace App\Services\FiscalDocument\Scenarios;

use App\Enum\FiscalDocument\NfseModel;
use App\Models\FiscalDocument;

class NationalNfseScenario extends MunicipalNfseScenario
{
    public function supports(FiscalDocument $document): bool
    {
        return $document->isNfse() && $this->resolveModel($document) === NfseModel::NACIONAL->value;
    }

    public function code(): string
    {
        return 'national_nfse';
    }

    public function channelCode(FiscalDocument $document): string
    {
        return 'nfse:nacional';
    }

    public function payloadBuilderKey(FiscalDocument $document): ?string
    {
        return 'nacional:default';
    }

    public function buildQueueGroupKey(FiscalDocument $document, string $series, int $environment): string
    {
        return implode(':', [
            $this->documentModel(),
            (int) $document->company_id,
            $series,
            $environment,
            NfseModel::NACIONAL->value,
        ]);
    }

    protected function resolveModel(FiscalDocument $document): string
    {
        return $document->nfse_model instanceof NfseModel
            ? $document->nfse_model->value
            : trim((string) $document->nfse_model);
    }
}
