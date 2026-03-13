<?php

namespace App\Services\Email\DTO;

final readonly class EmailMessage
{
    /**
     * @param string[] $to
     * @param string[] $cc
     * @param EmailAttachment[] $attachments
     */
    public function __construct(
        public array $to,
        public string $subject,
        public string $html,
        public array $cc = [],
        public ?string $text = null,
        public ?string $fromEmail = null,
        public ?string $fromName = null,
        public array $attachments = [],
    ) {}
}

