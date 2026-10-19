<?php

namespace App\Services\Commitments;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Models\CommitmentSetting;
use App\Models\Company;
use App\Models\User;
use App\Services\Access\UserAccessService;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommitmentSettingsService
{
    public function save(Company $company, int $customerId, User $user, array $data): CommitmentSetting
    {
        abort_unless(app(UserAccessService::class)->allows($user, $company, 'account_receivables.access', 'admin'), 403);

        if (! $company->partners()->whereKey($customerId)->wherePivot('is_active', true)->where('partners.is_active', true)->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'Selecione um cliente ativo vinculado à empresa.']);
        }

        $validated = Validator::make($data, [
            'is_enabled' => ['required', 'boolean'],
            'parser' => ['required', Rule::in(['chapeco'])],
            'nfse_service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('company_id', $company->id)->where('is_active', true)->whereNull('deleted_at')],
            'payment_method' => ['nullable', Rule::enum(Method::class)],
            'payment_condition' => ['nullable', Rule::enum(Condition::class)],
            'financial_category_id' => ['nullable', 'integer', Rule::exists('financial_categories', 'id')->where('company_id', $company->id)->where('is_active', true)->where('allow_receivable', true)->whereNull('deleted_at')],
            'financial_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('company_id', $company->id)->where('is_active', true)->whereNull('deleted_at')],
            // Reserve space for the mandatory patrimony and commitment references.
            'nfse_footer_message' => ['nullable', 'string', 'max:1800'],
        ])->validate();

        return DB::transaction(function () use ($company, $customerId, $user, $validated): CommitmentSetting {
            // Serialize first-time creation as well as updates for this company.
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $settings = CommitmentSetting::forCustomer($company->id, $customerId)->first()
                ?? new CommitmentSetting(['company_id' => $company->id, 'customer_id' => $customerId, 'created_by' => $user->id]);
            $audit = app(AuditRecorder::class);
            $before = $settings->exists ? $audit->snapshot($settings) : null;
            $settings->fill($validated)->save();
            $audit->recordModelEvent(
                $settings,
                'commitment.settings_saved',
                'Configuração de empenhos salva.',
                before: $before,
                after: $audit->snapshot($settings),
                actorUserId: $user->id,
            );

            return $settings;
        });
    }
}
