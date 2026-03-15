<?php

namespace App\Services\Email\Contracts;

use App\Services\Email\DTO\EmailMessage;

interface EmailProviderInterface
{
    /**
     * @return array{provider_message_id: string|null, provider_payload: array<string,mixed>|null}
     */
    public function send(EmailMessage $message): array;
}
