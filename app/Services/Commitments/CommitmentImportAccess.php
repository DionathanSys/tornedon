<?php

namespace App\Services\Commitments;

use App\Models\CommitmentSetting;
use App\Models\Company;
use App\Models\User;
use App\Services\Access\UserAccessService;
use Illuminate\Validation\ValidationException;

class CommitmentImportAccess
{
    public function authorize(int $companyId, int $customerId, int $userId): Company
    {
        $company = Company::query()->findOrFail($companyId);
        $user = User::query()->find($userId);
        abort_unless($user && app(UserAccessService::class)->allows($user, $company, 'account_receivables.access', 'admin'), 403);
        if (! $company->partners()->whereKey($customerId)->where('partners.is_active', true)->wherePivot('is_active', true)->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'Selecione um cliente ativo vinculado à empresa.']);
        }
        if (! CommitmentSetting::forCustomer($companyId, $customerId)->where('is_enabled', true)->where('parser', 'chapeco')->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'Habilite a importação de empenhos nas configurações deste cliente.']);
        }

        return $company;
    }
}
