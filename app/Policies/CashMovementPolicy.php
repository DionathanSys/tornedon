<?php

namespace App\Policies;

class CashMovementPolicy extends FinancialRecordPolicy
{
    protected string $permission = 'cash_movements.access';
}
