<?php

namespace App\Filament\Shop\Resources\CashMovements\Pages;

use App\Enum\Financial\CashMovementDirection;
use App\Filament\Shop\Resources\CashMovements\CashMovementResource;
use App\Models\CashMovement;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListCashMovements extends Page
{
    protected static string $resource = CashMovementResource::class;

    protected string $view = 'filament.shop.resources.cash-movements.pages.mobile-list';

    public string $activeTab = CashMovementDirection::INFLOW->value;

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    public function setTab(string $tab): void
    {
        if (! in_array($tab, [CashMovementDirection::INFLOW->value, CashMovementDirection::OUTFLOW->value, 'all'], true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    public function clearDateFilters(): void
    {
        $this->dateFrom = null;
        $this->dateTo = null;
    }

    public function getTitle(): string
    {
        return 'Caixa';
    }

    public function getHeading(): string
    {
        return 'Caixa';
    }

    /**
     * @return Collection<int, CashMovement>
     */
    public function getCashMovementsProperty(): Collection
    {
        return $this->baseQuery()
            ->when($this->activeTab !== 'all', fn (Builder $query): Builder => $query->where('direction', $this->activeTab))
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(80)
            ->get();
    }

    public function getInflowCountProperty(): int
    {
        return $this->baseQuery()->where('direction', CashMovementDirection::INFLOW->value)->count();
    }

    public function getOutflowCountProperty(): int
    {
        return $this->baseQuery()->where('direction', CashMovementDirection::OUTFLOW->value)->count();
    }

    public function getAllCountProperty(): int
    {
        return $this->baseQuery()->count();
    }

    public function getCreateUrl(): string
    {
        return CashMovementResource::getUrl('create');
    }

    public function getDetailUrl(CashMovement $record): string
    {
        return CashMovementResource::getUrl('edit', ['record' => $record->getKey()]);
    }

    private function baseQuery(): Builder
    {
        return CashMovement::query()
            ->where('company_id', Filament::getTenant()->id)
            ->when($this->dateFrom, fn (Builder $query): Builder => $query->whereDate('transaction_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn (Builder $query): Builder => $query->whereDate('transaction_date', '<=', $this->dateTo))
            ->with(['financialAccount', 'financialCategory']);
    }
}
