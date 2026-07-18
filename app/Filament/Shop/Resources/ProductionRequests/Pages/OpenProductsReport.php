<?php

namespace App\Filament\Shop\Resources\ProductionRequests\Pages;

use App\Enum\ProductionRequest\Status;
use App\Filament\Shop\Resources\ProductionRequests\ProductionRequestResource;
use App\Models\ProductionRequestItem;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OpenProductsReport extends Page
{
    protected static string $resource = ProductionRequestResource::class;

    protected string $view = 'filament.shop.resources.production-requests.pages.open-products-report';

    protected static ?string $title = 'Resumo em aberto';

    /**
     * @return Collection<int, object{product_id: int, product_name: string, unit_of_measure: string, orders_count: int, total_quantity: float}>
     */
    public function getRowsProperty(): Collection
    {
        return ProductionRequestItem::query()
            ->join('production_requests', 'production_requests.id', '=', 'production_request_items.production_request_id')
            ->join('products', 'products.id', '=', 'production_request_items.product_id')
            ->where('production_requests.company_id', Filament::getTenant()->id)
            ->where('production_requests.status', Status::OPEN->value)
            ->groupBy('production_request_items.product_id', 'products.name', 'production_request_items.unit_of_measure')
            ->orderBy('products.name')
            ->get([
                'production_request_items.product_id',
                'products.name as product_name',
                'production_request_items.unit_of_measure',
                DB::raw('COUNT(DISTINCT production_requests.id) as orders_count'),
                DB::raw('SUM(production_request_items.quantity) as total_quantity'),
            ]);
    }

    public function getOpenOrdersCountProperty(): int
    {
        return ProductionRequestResource::getModel()::query()
            ->where('company_id', Filament::getTenant()->id)
            ->where('status', Status::OPEN->value)
            ->count();
    }

    public function getProductsCountProperty(): int
    {
        return $this->rows->count();
    }

    public function getTotalQuantityProperty(): float
    {
        return round((float) $this->rows->sum(fn (object $row): float => (float) $row->total_quantity), 3);
    }

    public function getListUrl(): string
    {
        return ProductionRequestResource::getUrl();
    }
}
