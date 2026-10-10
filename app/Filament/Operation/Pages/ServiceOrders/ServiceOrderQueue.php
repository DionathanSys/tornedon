<?php

namespace App\Filament\Operation\Pages\ServiceOrders;

use App\Enum\ServiceOrder\State;
use App\Filament\Operation\Concerns\HasDateFilters;
use App\Filament\Operation\OperationPage;
use App\Models\ServiceOrder;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

class ServiceOrderQueue extends OperationPage
{
    use HasDateFilters, WithPagination;

    protected ?LengthAwarePaginator $pagination = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ClipboardDocumentList;

    protected static ?string $navigationLabel = 'Ordens';

    protected static ?string $title = 'Ordens de Serviço';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'ordens';

    protected string $view = 'filament.operation.pages.service-order-queue';

    public string $activeTab = 'open';

    public ?string $search = '';

    public array $orders = [];

    public int $openCount = 0;

    public int $closedCount = 0;

    public int $allCount = 0;

    public function mount(): void
    {
        $this->restoreDateFilters();
        $this->loadOrders();
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['open', 'closed', 'all'], true)) {
            return;
        }

        $this->activeTab = $tab;
        $this->resetPage();
    }

    public function loadOrders(): void
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            $this->orders = [];
            $this->openCount = 0;
            $this->closedCount = 0;
            $this->allCount = 0;
            $this->pagination = new LengthAwarePaginator([], 0, 15, $this->getPage());

            return;
        }

        $baseQuery = ServiceOrder::query()
            ->where('company_id', $tenant->getKey())
            ->where('status', '!=', State::CANCELLED->value)
            ->with([
                'customer:id,name',
                'technician:id,name',
                'equipment:id,name',
                'items',
                'requisition.items',
            ]);

        $this->applyDateRange($baseQuery, 'order_date');

        $this->openCount = (clone $baseQuery)->where('status', State::OPEN->value)->count();
        $this->closedCount = (clone $baseQuery)->where('status', State::CLOSED->value)->count();
        $this->allCount = (clone $baseQuery)->count();

        $query = clone $baseQuery;

        if ($this->activeTab === 'open') {
            $query->where('status', State::OPEN->value);
        } elseif ($this->activeTab === 'closed') {
            $query->where('status', State::CLOSED->value);
        }

        if (filled($this->search)) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('equipment', fn ($eq) => $eq->where('name', 'like', "%{$search}%"));
            });
        }

        $this->pagination = $query
            ->orderByDesc('order_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'page', $this->getPage())
            ->through(fn (ServiceOrder $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'customer' => $order->customer?->name ?? '-',
                'equipment' => $order->equipment?->name ?? '-',
                'technician' => $order->technician?->name ?? 'Sem técnico',
                'status' => $order->status?->description() ?? '-',
                'status_color' => $order->status?->color() ?? 'gray',
                'priority' => $order->priority?->description() ?? '-',
                'order_date' => $order->order_date?->format('d/m/Y'),
                'scheduled_date' => $order->scheduled_date?->format('d/m/Y'),
                'total' => 'R$ '.number_format((float) $order->grand_total_amount, 2, ',', '.'),
                'url' => ServiceOrderDetail::getUrl(
                    ['record' => $order->id],
                    tenant: $tenant,
                ),
            ]);
        $this->orders = $this->pagination->items();
    }

    protected function refreshList(): void
    {
        $this->loadOrders();
    }

    protected function getViewData(): array
    {
        if (! $this->pagination) {
            $this->loadOrders();
        }

        return ['pagination' => $this->pagination];
    }
}
