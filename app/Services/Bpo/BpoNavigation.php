<?php

namespace App\Services\Bpo;

use App\Filament\Bpo\Pages;
use App\Filament\Bpo\Resources;
use App\Models\Company;
use App\Models\User;
use App\Services\Access\CompanyAuthorization;

class BpoNavigation
{
    public function links(User $user, ?Company $company): array
    {
        if (! $company || ! app(BpoAccessService::class)->canAccessCompany($user, $company)) {
            return [];
        }
        $permissions = app(CompanyAuthorization::class)->effectivePermissions($user, $company);
        $items = [
            ['overview', 'Visão geral', 'heroicon-o-squares-2x2', null, Pages\CompanyOverview::getUrl(panel: 'bpo', tenant: $company)],
            ['payables', 'Contas a pagar', 'heroicon-o-arrow-up-right', 'account_payables.view', Resources\AccountPayableResource::getUrl(panel: 'bpo', tenant: $company)],
            ['receivables', 'Contas a receber', 'heroicon-o-arrow-down-left', 'account_receivables.view', Resources\AccountReceivableResource::getUrl(panel: 'bpo', tenant: $company)],
            ['cash', 'Movimentações', 'heroicon-o-arrows-right-left', 'cash_movements.view', Resources\CashMovementResource::getUrl(panel: 'bpo', tenant: $company)],
            ['reconciliation', 'Conciliação bancária', 'heroicon-o-building-library', 'bank_statements.view', Resources\BankStatementImportResource::getUrl(panel: 'bpo', tenant: $company)],
            ['fiscal', 'Documentos fiscais', 'heroicon-o-document-text', 'fiscal_documents.view', Pages\FiscalDocuments::getUrl(panel: 'bpo', tenant: $company)],
            ['reports', 'DRE gerencial', 'heroicon-o-chart-bar', 'reports.view', Pages\DreReport::getUrl(panel: 'bpo', tenant: $company)],
        ];
        $links = [];
        foreach ($items as [$key, $label, $icon, $permission, $url]) {
            if ($permission === null || isset($permissions[$permission])) {
                $links[] = compact('key', 'label', 'icon', 'url');
            }
        }
        if (Pages\CompanyTeam::canAccess()) {
            $links[] = ['key' => 'team', 'label' => 'Equipe e acessos', 'icon' => 'heroicon-o-user-group', 'url' => Pages\CompanyTeam::getUrl(panel: 'bpo', tenant: $company)];
        }

        return $links;
    }
}
