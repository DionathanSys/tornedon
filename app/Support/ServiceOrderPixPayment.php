<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\User;
use App\Services\Access\UserAccessService;

final class ServiceOrderPixPayment
{
    public const FEATURE = 'service_order_pix_payment';

    public static function entitlement(int $companyId): ?CompanyEntitlement
    {
        return CompanyEntitlement::query()->where('company_id', $companyId)
            ->where('feature', self::FEATURE)->active()->first();
    }

    public static function allows(?User $user, int $companyId): bool
    {
        $company = Company::find($companyId);
        $right = self::entitlement($companyId);

        if (! $user || ! $company || ! $right
            || ! app(UserAccessService::class)->allows($user, $company, 'service_orders.access', 'operation')
            || ! CompanyEntitlement::enabledFor($companyId, (string) config('pix.entitlement'))) {
            return false;
        }

        return data_get($right->metadata, 'user_access') === 'all'
            || in_array((string) $user->id, array_map('strval', (array) data_get($right->metadata, 'user_ids', [])), true);
    }
}
