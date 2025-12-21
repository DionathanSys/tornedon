<?php

namespace App\Domain\Exceptions;

use RuntimeException;

abstract class DomainException extends RuntimeException
{
    protected string $errorCode = 'domain_exception';

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}