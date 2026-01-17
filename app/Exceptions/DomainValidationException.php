<?php

namespace App\Exceptions;

final class DomainValidationException extends \RuntimeException
{
    public function __construct(
        public readonly array $errors
    ) {
        parent::__construct('Falha de validação');
    }
}

