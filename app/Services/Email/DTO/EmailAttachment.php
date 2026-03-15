<?php

namespace App\Services\Email\DTO;

final readonly class EmailAttachment
{
    public function __construct(
        public string $filename,
        public string $contentBase64,
        public string $mimeType = 'application/octet-stream',
        public bool $optional = false,
        public ?string $kind = null,
    ) {}
}
