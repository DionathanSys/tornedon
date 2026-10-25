<?php

namespace App\Services\Bpo;

use App\Models\AccountPayableInstallment;
use App\Models\AccountReceivableInstallment;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\User;
use App\Services\Access\CompanyAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class BpoPortfolioService
{
    public function __construct(private BpoAccessService $access, private CompanyAuthorization $authorization) {}

    public function rows(User $user): Collection
    {
        return $this->access->companies($user)->map(fn (Company $company) => $this->summary($user, $company));
    }

    public function summary(User $user, Company $company): array
    {
        throw_unless($this->access->canAccessCompany($user, $company), AuthorizationException::class);
        $permissions = $this->authorization->effectivePermissions($user, $company);
        $payable = isset($permissions['account_payables.view']);
        $receivable = isset($permissions['account_receivables.view']);
        $bank = isset($permissions['bank_statements.view']);
        $fiscal = isset($permissions['fiscal_documents.view']);
        $payables = AccountPayableInstallment::where('company_id', $company->id)->open()->whereHas('accountPayable',
            fn ($query) => $this->authorization->scopeQuery($query, $user, $company, 'account_payables.view'));
        $receivables = AccountReceivableInstallment::where('company_id', $company->id)->open()->whereHas('accountReceivable',
            fn ($query) => $this->authorization->scopeQuery($query, $user, $company, 'account_receivables.view'));
        $due = today()->addDays(7)->toDateString();
        $engagements = $this->authorization->assignments($user, $company)->whereNotNull('bpo_engagement_id');

        return [
            'company' => $company, 'permissions' => $permissions,
            'responsibles' => $engagements->map(fn ($assignment) => $assignment->engagement->responsible->name)->unique()->values()->all(),
            'is_responsible' => $engagements->contains(fn ($assignment) => (int) $assignment->engagement->responsible_id === (int) $user->id),
            'payable_due' => $payable ? (clone $payables)->whereDate('due_date', '<=', $due)->count() : null,
            'payable_overdue' => $payable ? (clone $payables)->whereDate('due_date', '<', today())->count() : null,
            'receivable_overdue' => $receivable ? (clone $receivables)->whereDate('due_date', '<', today())->count() : null,
            'payable_balance' => $payable ? (float) (clone $payables)->sum('balance_amount') / 100 : null,
            'receivable_balance' => $receivable ? (float) (clone $receivables)->sum('balance_amount') / 100 : null,
            'reconciliation_pending' => $bank ? BankStatementLine::where('company_id', $company->id)->whereHas('import',
                fn ($query) => $this->authorization->scopeQuery($query, $user, $company, 'bank_statements.view'))
                ->whereIn('reconciliation_status', ['pending', 'needs_review', 'reversed'])->count() : null,
            'fiscal_pending' => $fiscal ? $this->authorization->scopeQuery(FiscalDocument::query(), $user, $company, 'fiscal_documents.view')->where(fn ($query) => $query
                ->whereIn('nfe_status', ['rejected', 'rps_reconciliation_pending'])->orWhereIn('nfse_status', ['rejected', 'rps_reconciliation_pending']))->count() : null,
        ];
    }
}
