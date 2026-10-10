<?php

namespace App\Filament\Operation\Concerns;

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;

trait HasDateFilters
{
    public bool $showFilters = false;

    public array $dateFilter = ['from' => null, 'until' => null];

    #[Locked]
    public ?string $dateFrom = null;

    #[Locked]
    public ?string $dateUntil = null;

    protected function restoreDateFilters(): void
    {
        $key = $this->dateFilterSessionKey();
        $saved = $key ? session()->get($key, []) : [];
        $this->dateFilter = [
            'from' => is_array($saved) ? ($saved['from'] ?? null) : null,
            'until' => is_array($saved) ? ($saved['until'] ?? null) : null,
        ];

        if (Validator::make(['dateFilter' => $this->dateFilter], $this->dateFilterRules())->fails()) {
            $this->dateFilter = ['from' => null, 'until' => null];

            if ($key) {
                session()->forget($key);
            }
        }

        $this->dateFrom = $this->dateFilter['from'] ?: null;
        $this->dateUntil = $this->dateFilter['until'] ?: null;
    }

    public function applyDateFilters(): void
    {
        $this->dateFilter = [
            'from' => filled($this->dateFilter['from'] ?? null) ? $this->dateFilter['from'] : null,
            'until' => filled($this->dateFilter['until'] ?? null) ? $this->dateFilter['until'] : null,
        ];
        $validated = $this->validate($this->dateFilterRules(), [
            'dateFilter.from.date_format' => 'Informe uma data inicial válida.',
            'dateFilter.until.date_format' => 'Informe uma data final válida.',
            'dateFilter.until.after_or_equal' => 'A data final deve ser igual ou posterior à data inicial.',
        ], ['dateFilter.from' => 'data inicial', 'dateFilter.until' => 'data final'])['dateFilter'];

        $this->dateFrom = $validated['from'];
        $this->dateUntil = $validated['until'];

        if ($key = $this->dateFilterSessionKey()) {
            if ($this->dateFrom || $this->dateUntil) {
                session()->put($key, $validated);
            } else {
                session()->forget($key);
            }
        }

        $this->resetPage();
    }

    public function clearDateFilters(): void
    {
        $this->dateFilter = ['from' => null, 'until' => null];
        $this->dateFrom = null;
        $this->dateUntil = null;
        $this->resetValidation();

        if ($key = $this->dateFilterSessionKey()) {
            session()->forget($key);
        }

        $this->resetPage();
    }

    public function appliedDateLabel(): ?string
    {
        $from = $this->dateFrom ? CarbonImmutable::parse($this->dateFrom)->format('d/m/Y') : null;
        $until = $this->dateUntil ? CarbonImmutable::parse($this->dateUntil)->format('d/m/Y') : null;

        return match (true) {
            $from !== null && $until !== null => "{$from} até {$until}",
            $from !== null => "Desde {$from}",
            $until !== null => "Até {$until}",
            default => null,
        };
    }

    protected function applyDateRange(Builder $query, string $column): Builder
    {
        return $query
            ->when($this->dateFrom, fn (Builder $query) => $query->where($column, '>=', $this->dateFrom))
            ->when($this->dateUntil, fn (Builder $query) => $query->where($column, '<', CarbonImmutable::parse($this->dateUntil)->addDay()->toDateString()));
    }

    public function updatedPaginators(int $page, string $pageName): void
    {
        $this->refreshList();
    }

    private function dateFilterRules(): array
    {
        $until = ['nullable', 'string', 'date_format:Y-m-d'];

        if (filled($this->dateFilter['from'] ?? null)) {
            $until[] = 'after_or_equal:dateFilter.from';
        }

        return ['dateFilter.from' => ['nullable', 'string', 'date_format:Y-m-d'], 'dateFilter.until' => $until];
    }

    private function dateFilterSessionKey(): ?string
    {
        $tenantId = Filament::getTenant()?->getKey();

        return $tenantId && Auth::id() ? 'operation.date_filters.'.Auth::id().'.'.$tenantId.'.'.class_basename(static::class) : null;
    }

    abstract protected function refreshList(): void;
}
