<?php

namespace App\Filament\Support\Concerns;

use App\Models\FiscalDocument;
use App\Services\Access\CompanyAuthorization;
use App\Services\Access\UserAccessService;
use Filament\Facades\Filament;

trait AuthorizesFiscalDocumentListing
{
    // Private state lives only for this request, so revocations affect the next interaction.
    private ?array $listingPermissions = null;

    private ?bool $listingUsesRoles = null;

    private array $listingLegacyPermissions = [];

    private ?bool $listingCanManageIssues = null;

    public function canUseFiscalDocumentAction(FiscalDocument $record, string $ability): bool
    {
        $user = Filament::auth()->user();
        $company = Filament::getTenant();
        if (! $user || ! $company || (int) $record->company_id !== (int) $company->id) {
            return false;
        }

        $access = app(CompanyAuthorization::class);
        $this->listingUsesRoles ??= $access->requiresRoleAuthorization($user, $company, Filament::getCurrentPanel()?->getId());
        $permission = 'fiscal_documents.'.$ability;
        if (! $this->listingUsesRoles) {
            return $this->listingLegacyPermissions[$permission] ??= app(UserAccessService::class)->allows($user, $company, $permission);
        }

        $this->listingPermissions ??= $user->canAccessTenant($company) ? $access->effectivePermissions($user, $company) : [];

        return $access->recordMatchesScopes($record, $user, $this->listingPermissions[$permission] ?? []);
    }

    public function canManageFiscalIssues(): bool
    {
        if ($this->listingCanManageIssues !== null) {
            return $this->listingCanManageIssues;
        }

        $user = Filament::auth()->user();
        $company = Filament::getTenant();
        if (! $user || ! $company || ! $user->canAccessTenant($company)) {
            return $this->listingCanManageIssues = false;
        }

        $access = app(CompanyAuthorization::class);
        $membership = $access->membership($user, $company);
        $this->listingPermissions ??= $access->effectivePermissions($user, $company);

        return $this->listingCanManageIssues = $membership && (
            ($membership->access_mode === 'legacy' && $membership->role === 'owner')
            || in_array('company', $this->listingPermissions['team.manage'] ?? [], true)
        );
    }
}
