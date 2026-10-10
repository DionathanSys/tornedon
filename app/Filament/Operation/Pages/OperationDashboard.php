<?php

namespace App\Filament\Operation\Pages;

use App\Enum\ServiceOrder\State;
use App\Filament\Operation\Pages\ServiceOrders\ServiceOrderDetail;
use App\Models\ServiceOrder;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class OperationDashboard extends Dashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::Home;

    protected static ?string $navigationLabel = 'Início';

    protected static ?string $title = 'Início';

    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.operation.pages.dashboard';

    public array $todayStats = [];

    public array $myStats = [];

    public array $recentOrders = [];

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            return;
        }

        $userId = Auth::id();

        $todayQuery = ServiceOrder::query()
            ->where('company_id', $tenant->getKey())
            ->whereDate('order_date', today())
            ->where('status', '!=', State::CANCELLED->value)
            ->with(['items', 'requisition.items']);

        $todayOrders = $todayQuery->get();

        $myQuery = ServiceOrder::query()
            ->where('company_id', $tenant->getKey())
            ->where('technician_id', $userId)
            ->where('status', State::OPEN->value);

        $this->todayStats = [
            'total' => $todayOrders->count(),
            'open' => $todayOrders->where('status', State::OPEN)->count(),
            'closed' => $todayOrders->where('status', State::CLOSED)->count(),
            'revenue' => $this->formatMoney($todayOrders->sum(
                fn (ServiceOrder $order): float => (float) $order->grand_total_amount
            )),
        ];

        $this->myStats = [
            'pending' => $myQuery->count(),
            'scheduled_today' => ServiceOrder::query()
                ->where('company_id', $tenant->getKey())
                ->where('technician_id', $userId)
                ->whereDate('scheduled_date', today())
                ->where('status', State::OPEN->value)
                ->count(),
        ];

        $this->recentOrders = ServiceOrder::query()
            ->where('company_id', $tenant->getKey())
            ->where('status', State::OPEN->value)
            ->with(['customer:id,name', 'equipment:id,name'])
            ->orderByDesc('order_date')
            ->limit(5)
            ->get()
            ->map(fn (ServiceOrder $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'customer' => $order->customer?->name ?? '-',
                'equipment' => $order->equipment?->name ?? '-',
                'status' => $order->status?->description() ?? '-',
                'status_color' => $order->status?->color() ?? 'gray',
                'order_date' => $order->order_date?->format('d/m/Y'),
                'url' => ServiceOrderDetail::getUrl(
                    ['record' => $order->id],
                    tenant: $tenant,
                ),
            ])
            ->toArray();
    }

    private function formatMoney(float $amount): string
    {
        return 'R$ '.number_format($amount, 2, ',', '.');
    }
}
