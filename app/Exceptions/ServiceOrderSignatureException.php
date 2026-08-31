<?php

namespace App\Exceptions;

final class ServiceOrderSignatureException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
