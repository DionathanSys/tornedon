<?php

namespace App\Filament\Bpo\Resources\AccountPayableInstallments\Pages;

use App\Filament\Bpo\Concerns\HasFinancialTitlePermissions;
use App\Filament\Bpo\Concerns\HasWorkbenchHeader;
use App\Filament\Bpo\Resources\AccountPayableInstallmentResource;
use App\Filament\Bpo\Resources\AccountPayableResource;
use App\Filament\Clusters\Financial\Concerns\HasFinancialTabs;
use App\Models\AccountPayableInstallment;
use App\Models\FinancialCategory;
use App\Services\Access\CompanyAuthorization;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ListInstallments extends ListRecords
{
    use HasFinancialTabs;
    use HasFinancialTitlePermissions;
    use HasWorkbenchHeader;

    protected static string $resource = AccountPayableInstallmentResource::class;

    protected static string $layout = 'components.bpo.layout';

    protected string $view = 'filament.bpo.pages.workbench-list';

    private ?array $categoryLabels = null;

    private ?array $tabCounts = null;

    public function getTitle(): string
    {
        return 'Parcelas a Pagar';
    }

    public function workbenchEyebrow(): string
    {
        return 'DESPESAS · PARCELAS A PAGAR';
    }

    public function workbenchDescription(): string
    {
        return 'Acompanhe cada parcela, seu vencimento, saldo e pagamentos.';
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('create_account_payable')->label('Novo lançamento')
            ->authorize(fn (): bool => $this->canOperateTitle('create'))
            ->url(AccountPayableResource::getUrl('create'))];
    }

    protected function installmentTabCount(string $tab): int
    {
        if ($this->tabCounts === null) {
            $query = static::getResource()::getEloquentQuery();
            $this->applyFiltersToTableQuery($query);
            $this->applySearchToTableQuery($query);
            $query->select(['account_payable_installments.status', 'account_payable_installments.ignored_at',
                'account_payable_installments.due_date', 'account_payable_installments.balance_amount'])
                ->withExists(['accountPayable as parent_is_active' => fn (Builder $parent) => $parent->where('status', '!=', 'cancelled')]);

            $open = "ignored_at IS NULL AND status != 'cancelled' AND balance_amount > 0 AND parent_is_active";
            $today = today()->toDateString();
            $counts = DB::query()->fromSub($query->toBase(), 'installment_counts')
                ->selectRaw("COUNT(*) as all_count,
                    SUM(CASE WHEN {$open} THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN {$open} AND DATE(due_date) < ? THEN 1 ELSE 0 END) as overdue,
                    SUM(CASE WHEN {$open} AND DATE(due_date) = ? THEN 1 ELSE 0 END) as today,
                    SUM(CASE WHEN ignored_at IS NULL AND status != 'cancelled' AND balance_amount <= 0 THEN 1 ELSE 0 END) as settled,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                    SUM(CASE WHEN ignored_at IS NOT NULL THEN 1 ELSE 0 END) as ignored", [$today, $today])
                ->first();
            $this->tabCounts = (array) $counts;
            $this->tabCounts['all'] = $this->tabCounts['all_count'];
        }

        return (int) ($this->tabCounts[$tab] ?? 0);
    }

    public function canOperateInstallment(string $ability, AccountPayableInstallment $record): bool
    {
        return (int) $record->company_id === (int) Filament::getTenant()->id
            && $record->accountPayable
            && $this->canOperateTitle('view', $record->accountPayable)
            && $this->canOperateTitle($ability, $record->accountPayable)
            && ($ability !== 'change_status_without_movement' || $this->canOperateTitle('update', $record->accountPayable));
    }

    public function categoryLabel(AccountPayableInstallment $record): ?string
    {
        if (! $record->financial_category_id) {
            return null;
        }

        $this->categoryLabels ??= FinancialCategory::hierarchyOptionsForCompany(Filament::getTenant()->id);

        return $this->categoryLabels[$record->financial_category_id] ?? null;
    }

    public function authorizeInstallment(string $ability, AccountPayableInstallment $record): void
    {
        abort_unless((int) $record->company_id === (int) Filament::getTenant()->id, 403);
        Gate::authorize('view', $record);
        $parent = $record->accountPayable;
        abort_unless($parent && app(CompanyAuthorization::class)->allowsRecord(
            Filament::auth()->user(), Filament::getTenant(), 'account_payables.'.$ability, $parent,
        ), 403);

        if ($ability === 'change_status_without_movement') {
            Gate::authorize('update', $record);
        }
    }
}
