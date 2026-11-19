<?php

namespace App\Filament\Clusters\Financial\Resources\SefazDistributionDocuments\Actions;

use App\Models\AccountPayable;
use App\Models\SefazDistributionDocument;
use App\Services\Access\CompanyAuthorization;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

final class PayableAccess
{
    public static function allows(SefazDistributionDocument $document, string $operation = 'create', ?AccountPayable $payable = null): bool
    {
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant();
        if (! $user || ! $tenant || (int) $document->company_id !== (int) $tenant->id) {
            return false;
        }
        if (! Gate::allows($operation === 'create' ? 'create' : ($payable ? 'view' : 'viewAny'), $payable ?? AccountPayable::class)) {
            return false;
        }
        $access = app(CompanyAuthorization::class);
        if (! $access->requiresRoleAuthorization($user, $tenant, Filament::getCurrentPanel()?->getId())) {
            return true;
        }

        return $payable
            ? $access->allowsRecord($user, $tenant, 'account_payables.'.$operation, $payable)
            : $access->allows($user, $tenant, 'account_payables.'.$operation)
                && ($operation !== 'create' || $access->allows($user, $tenant, 'account_payables.update'));
    }
}
