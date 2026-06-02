<?php

namespace App\Filament\Mobile\Pages;

use App\Enum\ServiceOrder\State;
use App\Filament\Mobile\Resources\MobileServiceOrders\MobileServiceOrderResource;
use App\Models\ServiceOrder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use UnitEnum;

class MobileServiceOrdersDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'OS Hoje';

    protected static ?string $title = 'Dashboard de Ordens do Dia';

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = -1;

    protected static ?string $slug = 'service-orders/dashboard';

    protected string $view = 'filament.mobile.pages.mobile-service-orders-dashboard';

    public array $stats = [];

    public array $orders = [];

    public function mount(): void
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            $this->stats = $this->emptyStats();
            $this->orders = [];

            return;
        }

        $orders = ServiceOrder::query()
            ->where('company_id', $tenant->getKey())
            ->whereDate('order_date', today())
            ->with([
                'customer:id,name',
                'technician:id,name',
                'items',
                'requisition.items',
            ])
            ->orderByDesc('created_at')
            ->get();

        $this->stats = $this->buildStats($orders);
        $this->orders = $this->buildOrders($orders);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_service_orders')
                ->label('Ver listagem')
                ->icon('heroicon-o-clipboard-document-list')
                ->url(MobileServiceOrderResource::getUrl('index', [
                    'tenant' => Filament::getTenant(),
                ])),
        ];
    }

    /**
     * @param  EloquentCollection<int, ServiceOrder>  $orders
     * @return array<string, array{label:string,value:string,description:string,color:string}>
     */
    private function buildStats(EloquentCollection $orders): array
    {
        $count = $orders->count();
        $total = round((float) $orders->sum(fn (ServiceOrder $serviceOrder): float => (float) $serviceOrder->grand_total_amount), 2);
        $pending = $orders->filter(
            fn (ServiceOrder $serviceOrder): bool => $serviceOrder->status === State::OPEN
        )->count();

        return [
            'count' => [
                'label' => 'Ordens do dia',
                'value' => number_format($count, 0, ',', '.'),
                'description' => 'OS criadas com data de hoje',
                'color' => 'text-zinc-900',
            ],
            'total' => [
                'label' => 'Valor total',
                'value' => $this->formatMoney($total),
                'description' => 'Soma do total geral das OS',
                'color' => 'text-emerald-700',
            ],
            'average_ticket' => [
                'label' => 'Ticket médio',
                'value' => $this->formatMoney($count > 0 ? round($total / $count, 2) : 0),
                'description' => 'Valor total dividido pela quantidade',
                'color' => 'text-sky-700',
            ],
            'pending' => [
                'label' => 'Pendentes do dia',
                'value' => number_format($pending, 0, ',', '.'),
                'description' => 'OS de hoje ainda abertas',
                'color' => 'text-amber-700',
            ],
        ];
    }

    /**
     * @param  EloquentCollection<int, ServiceOrder>  $orders
     * @return array<int, array<string, string|null>>
     */
    private function buildOrders(EloquentCollection $orders): array
    {
        return $orders
            ->map(fn (ServiceOrder $serviceOrder): array => [
                'number' => $serviceOrder->number,
                'customer' => $serviceOrder->customer?->name ?? '-',
                'status' => $serviceOrder->status?->description() ?? '-',
                'status_color' => $serviceOrder->status?->color() ?? 'gray',
                'technician' => $serviceOrder->technician?->name ?? 'Técnico não atribuído',
                'total' => $this->formatMoney((float) $serviceOrder->grand_total_amount),
                'order_date' => $serviceOrder->order_date?->format('d/m/Y'),
                'edit_url' => MobileServiceOrderResource::getUrl('edit', [
                    'record' => $serviceOrder,
                    'tenant' => Filament::getTenant(),
                ]),
            ])
            ->all();
    }

    /**
     * @return array<string, array{label:string,value:string,description:string,color:string}>
     */
    private function emptyStats(): array
    {
        return [
            'count' => [
                'label' => 'Ordens do dia',
                'value' => '0',
                'description' => 'OS criadas com data de hoje',
                'color' => 'text-zinc-900',
            ],
            'total' => [
                'label' => 'Valor total',
                'value' => $this->formatMoney(0),
                'description' => 'Soma do total geral das OS',
                'color' => 'text-emerald-700',
            ],
            'average_ticket' => [
                'label' => 'Ticket médio',
                'value' => $this->formatMoney(0),
                'description' => 'Valor total dividido pela quantidade',
                'color' => 'text-sky-700',
            ],
            'pending' => [
                'label' => 'Pendentes do dia',
                'value' => '0',
                'description' => 'OS de hoje ainda abertas',
                'color' => 'text-amber-700',
            ],
        ];
    }

    private function formatMoney(float $amount): string
    {
        return 'R$ ' . number_format($amount, 2, ',', '.');
    }
}
