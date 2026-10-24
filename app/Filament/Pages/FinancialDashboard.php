<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\AccountReceivableResource;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Services\Financial\FinancialDashboardService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\WithPagination;
use UnitEnum;

class FinancialDashboard extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Dashboard Financeiro';

    protected static ?string $title = 'Dashboard Financeiro';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.financial-dashboard';

    public string $period = 'today';

    public string $search = '';

    public static function canAccess(): bool
    {
        return Filament::getTenant() && (Gate::allows('viewAny', AccountReceivable::class) || Gate::allows('viewAny', AccountPayable::class));
    }

    public function updatedPeriod(): void
    {
        $this->resetPage('receivablePage');
        $this->resetPage('payablePage');
    }

    public function updatedSearch(): void
    {
        $this->updatedPeriod();
    }

    public function getDashboardProperty(): array
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(array_key_exists($this->period, FinancialDashboardService::PERIODS), 422);

        $service = app(FinancialDashboardService::class);
        $companyId = (int) Filament::getTenant()->getKey();
        $data = [];

        foreach (['receivable' => AccountReceivable::class, 'payable' => AccountPayable::class] as $type => $model) {
            if (! Gate::allows('viewAny', $model)) {
                continue;
            }

            $relation = $type === 'receivable' ? 'accountReceivable' : 'accountPayable';
            $party = $type === 'receivable' ? 'customer' : 'supplier';
            $query = $service->query($companyId, $type, $this->period)
                ->with("{$relation}.{$party}");

            if (filled($this->search)) {
                $search = '%'.$this->search.'%';
                $query->where(fn (Builder $query) => $query
                    ->where('description', 'like', $search)
                    ->orWhereHas($relation, fn (Builder $account) => $account
                        ->where('document_number', 'like', $search)
                        ->orWhere('description', 'like', $search)
                        ->orWhere('manual_counterparty_name', 'like', $search)
                        ->orWhereHas($party, fn (Builder $partner) => $partner->where('name', 'like', $search))));
            }

            $data[$type] = [
                'total' => $service->total($companyId, $type, $this->period),
                'overdue' => $service->total($companyId, $type, 'overdue'),
                'rows' => $query->orderBy('due_date')->orderBy('id')->paginate(10, ['*'], $type.'Page'),
            ];
        }

        return $data;
    }

    public function accountUrl(Model $account, string $type): ?string
    {
        if (! Gate::allows('view', $account)) {
            return null;
        }

        $resource = $type === 'receivable' ? AccountReceivableResource::class : AccountPayableResource::class;

        return $resource::getUrl('view', ['record' => $account]);
    }
}
