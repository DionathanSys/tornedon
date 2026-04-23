<?php

namespace App\Domain\DTO\Fiscal;

readonly class FiscalEmissionPreflightResult
{
    /**
     * @param  array<int|string,mixed>  $errors
     * @param  array<int,string>  $warnings
     */
    public function __construct(
        public bool $passed,
        public int $companyId,
        public string $documentModel,
        public string $operationNature,
        public string $series,
        public int $environment,
        public string $queueGroupKey,
        public string $scenarioCode,
        public ?int $candidateNumber = null,
        public array $errors = [],
        public array $warnings = [],
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'passed'            => $this->passed,
            'company_id'        => $this->companyId,
            'document_model'    => $this->documentModel,
            'operation_nature'  => $this->operationNature,
            'series'            => $this->series,
            'environment'       => $this->environment,
            'queue_group_key'   => $this->queueGroupKey,
            'scenario_code'     => $this->scenarioCode,
            'candidate_number'  => $this->candidateNumber,
            'errors'            => $this->errors,
            'warnings'          => $this->warnings,
        ];
    }
}
