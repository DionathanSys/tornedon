<?php

namespace App\Domain\Exceptions\ProductionOrder;

use DomainException;

class InvalidStateTransitionException extends DomainException
{
    protected string $errorCode = 'invalid_state_transition';

    public static function make(string $action, string $state): self
    {
        return new self(
            "A ação '{$action}' não é permitida no estado '{$state}'."
        );
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
