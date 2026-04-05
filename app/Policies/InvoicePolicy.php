<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->belongsToCompany($invoice->company_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->belongsToCompany($invoice->company_id);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->belongsToCompany($invoice->company_id);
    }

    public function restore(User $user, Invoice $invoice): bool
    {
        return $user->belongsToCompany($invoice->company_id);
    }

    public function forceDelete(User $user, Invoice $invoice): bool
    {
        return $user->belongsToCompany($invoice->company_id);
    }
}
