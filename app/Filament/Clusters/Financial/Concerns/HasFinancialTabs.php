<?php

namespace App\Filament\Clusters\Financial\Concerns;

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

trait HasFinancialTabs
{
    protected function installmentTabQuery(string $tab, Builder $query): Builder
    {
        if ($query->getModel() instanceof AccountPayable || $query->getModel() instanceof AccountReceivable) {
            return match ($tab) {
                'pending' => $query->whereHas('installments', fn (Builder $q) => $q->open()),
                'overdue' => $query->whereHas('installments', fn (Builder $q) => $q->open()->whereDate('due_date', '<', today())),
                'today' => $query->whereHas('installments', fn (Builder $q) => $q->open()->whereDate('due_date', today())),
                'settled' => $query->whereIn('status', ['paid', 'received']),
                'cancelled' => $query->where('status', 'cancelled'),
                'ignored' => $query->whereHas('installments', fn (Builder $q) => $q->whereNotNull('ignored_at')),
                default => $query,
            };
        }

        return match ($tab) {
            'pending' => $query->open(),
            'overdue' => $query->open()->whereDate('due_date', '<', today()),
            'today' => $query->open()->whereDate('due_date', today()),
            'settled' => $query->considered()->where('balance_amount', '<=', 0)->where('status', '!=', 'cancelled'),
            'cancelled' => $query->where('status', 'cancelled'),
            'ignored' => $query->whereNotNull('ignored_at'),
            default => $query,
        };
    }

    protected function installmentTabCount(string $tab): int
    {
        // Start without the active tab; apply exactly the table's filters and search.
        $query = static::getResource()::getEloquentQuery();
        $this->applyFiltersToTableQuery($query);
        $this->applySearchToTableQuery($query);

        return $this->installmentTabQuery($tab, $query)->count();
    }

    public function getTabs(): array
    {
        $tabs = [];
        foreach (['pending' => 'Em aberto', 'overdue' => 'Vencidas', 'today' => 'Vencem hoje', 'settled' => 'Liquidadas', 'cancelled' => 'Canceladas', 'ignored' => 'Ignoradas', 'all' => 'Todas'] as $key => $label) {
            $tabs[$key] = Tab::make($label)
                ->modifyQueryUsing(fn (Builder $query) => $this->installmentTabQuery($key, $query))
                ->badge(fn () => $this->installmentTabCount($key))
                ->badgeColor(match ($key) {
                    'overdue' => 'danger', 'settled' => 'success', default => 'gray'
                });
        }

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }
}
