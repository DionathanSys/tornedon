<?php

namespace App\Services\Fiscal\Sefaz\DTO;

final class DfeDistributionResult
{
    /**
     * @param  array<int,DfeDistributionDocument>  $documents
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $statusCode,
        public readonly string $statusMessage,
        public readonly ?string $ultNsu,
        public readonly ?string $maxNsu,
        public readonly string $rawXml,
        public readonly array $documents = [],
    ) {
    }

    /**
     * @return array{
     *     success:bool,
     *     status_code:string,
     *     status_message:string,
     *     ult_nsu:?string,
     *     max_nsu:?string,
     *     documents:array<int, array{nsu:string,schema:string,access_key:?string,filename:string}>
     * }
     */
    public function toSummary(): array
    {
        return [
            'success' => $this->success,
            'status_code' => $this->statusCode,
            'status_message' => $this->statusMessage,
            'ult_nsu' => $this->ultNsu,
            'max_nsu' => $this->maxNsu,
            'documents' => array_map(
                static fn (DfeDistributionDocument $document): array => $document->toSummary(),
                $this->documents,
            ),
        ];
    }
}
