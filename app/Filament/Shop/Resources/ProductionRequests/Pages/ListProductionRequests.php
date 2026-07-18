<?php

namespace App\Filament\Shop\Resources\ProductionRequests\Pages;

use App\Enum\ProductionRequest\Status;
use App\Filament\Shop\Resources\ProductionRequests\ProductionRequestResource;
use App\Models\ProductionRequest;
use App\Notification\NotifyService as notify;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListProductionRequests extends Page
{
    protected static string $resource = ProductionRequestResource::class;

    protected string $view = 'filament.shop.resources.production-requests.pages.mobile-list';

    protected static ?string $title = 'Pedidos';

    public string $activeTab = 'open';

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['open', 'delivered', 'all'], true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    /**
     * @return Collection<int, ProductionRequest>
     */
    public function getProductionRequestsProperty(): Collection
    {
        return $this->baseQuery()
            ->when($this->activeTab === 'open', fn (Builder $query): Builder => $query->where('status', Status::OPEN->value))
            ->when($this->activeTab === 'delivered', fn (Builder $query): Builder => $query->where('status', Status::DELIVERED->value))
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit(60)
            ->get();
    }

    public function getOpenCountProperty(): int
    {
        return $this->baseQuery()->where('status', Status::OPEN->value)->count();
    }

    public function getDeliveredCountProperty(): int
    {
        return $this->baseQuery()->where('status', Status::DELIVERED->value)->count();
    }

    public function getAllCountProperty(): int
    {
        return $this->baseQuery()->count();
    }

    public function getCreateUrl(): string
    {
        return ProductionRequestResource::getUrl('create');
    }

    public function getDetailUrl(ProductionRequest $record): string
    {
        return ProductionRequestResource::getUrl('edit', ['record' => $record->getKey()]);
    }

    public function deleteProductionRequest(int $recordId): void
    {
        $record = ProductionRequest::query()
            ->where('company_id', Filament::getTenant()->id)
            ->whereKey($recordId)
            ->firstOrFail();

        if ($record->status !== Status::OPEN) {
            notify::error('Somente pedidos abertos podem ser excluidos.');

            return;
        }

        $record->delete();

        notify::success('Pedido excluido com sucesso.');
    }

    private function baseQuery(): Builder
    {
        return ProductionRequest::query()
            ->where('company_id', Filament::getTenant()->id)
            ->with(['customer', 'items']);
    }
}
