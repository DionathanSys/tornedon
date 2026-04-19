<?php

namespace App\Services\Fiscal\Sefaz\DTO;

final class DfeDistributionDocument
{
    public function __construct(
        public readonly string $nsu,
        public readonly string $schema,
        public readonly string $xml,
        public readonly ?string $accessKey = null,
    ) {
    }

    public function filename(): string
    {
        $parts = array_filter([
            $this->schema !== '' ? $this->schema : 'dfe',
            $this->nsu !== '' ? $this->nsu : null,
            $this->accessKey,
        ]);

        return implode('-', $parts) . '.xml';
    }

    /**
     * @return array{nsu:string,schema:string,access_key:?string,filename:string}
     */
    public function toSummary(): array
    {
        return [
            'nsu' => $this->nsu,
            'schema' => $this->schema,
            'access_key' => $this->accessKey,
            'filename' => $this->filename(),
        ];
    }
}
