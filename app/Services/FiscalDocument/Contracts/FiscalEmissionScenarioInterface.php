<?php

namespace App\Services\FiscalDocument\Contracts;

use App\Models\FiscalDocument;

interface FiscalEmissionScenarioInterface
{
    public function supports(FiscalDocument $document): bool;

    public function code(): string;

    public function documentModel(): string;

    public function channelCode(FiscalDocument $document): string;

    public function payloadBuilderKey(FiscalDocument $document): ?string;

    public function resolveSeries(FiscalDocument $document): string;

    public function resolveOperationNature(FiscalDocument $document): ?string;

    public function resolveCandidateNumber(FiscalDocument $document, string $series): ?int;

    public function buildQueueGroupKey(FiscalDocument $document, string $series, int $environment): string;

    /**
     * @param  array<int|string,mixed>  $errors
     */
    public function validate(FiscalDocument $document, array &$errors): void;

    public function resolveContext(FiscalDocument $document): \App\Domain\DTO\Fiscal\ScenarioContext;
}
