<?php

namespace App\Services\Email\Contracts;

use App\Services\Email\DTO\EmailMessage;

interface EmailProviderInterface
{
    public function send(EmailMessage $message): void;
}

