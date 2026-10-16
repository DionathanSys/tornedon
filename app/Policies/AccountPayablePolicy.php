<?php

namespace App\Policies;

class AccountPayablePolicy extends FinancialRecordPolicy
{
    protected string $permission = 'account_payables.access';
}
