<?php

namespace App\Exceptions;

use RuntimeException;

/** @internal Carries the legacy failure result across a database rollback. */
final class FinancialTransactionFailed extends RuntimeException
{
    public function __construct(public readonly mixed $result)
    {
        parent::__construct('A operação financeira não foi concluída.');
    }
}
