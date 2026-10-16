<?php

namespace App\Traits;

use App\Exceptions\FinancialTransactionFailed;
use Closure;
use Illuminate\Support\Facades\DB;

trait RunsFinancialTransactions
{
    /**
     * Roll back service failures as well as exceptions, preserving the public
     * null/false result and the error details used by existing callers.
     */
    private function financialTransaction(Closure $callback): mixed
    {
        try {
            return DB::transaction(function () use ($callback) {
                $result = $callback();

                if ($this->hasError()) {
                    throw new FinancialTransactionFailed($result);
                }

                return $result;
            });
        } catch (FinancialTransactionFailed $exception) {
            return $exception->result;
        }
    }
}
