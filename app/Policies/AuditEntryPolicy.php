<?php

namespace App\Policies;

use App\Models\AuditEntry;
use App\Models\User;

class AuditEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewAuditLogs();
    }

    public function view(User $user, AuditEntry $auditEntry): bool
    {
        return $user->canViewAuditLogs() && $user->belongsToCompany($auditEntry->company_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditEntry $auditEntry): bool
    {
        return false;
    }

    public function delete(User $user, AuditEntry $auditEntry): bool
    {
        return false;
    }
}
