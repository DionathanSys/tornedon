<?php

namespace App\Policies;

class AccountReceivablePolicy extends FinancialRecordPolicy
{
    protected string $permission = 'account_receivables.access';
}
