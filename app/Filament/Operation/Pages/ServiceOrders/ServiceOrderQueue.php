<?php

namespace App\Filament\Operation\Pages\ServiceOrders;

use App\Enum\ServiceOrder\State;
use App\Filament\Operation\Concerns\HasDateFilters;
use App\Filament\Operation\OperationPage;
use App\Models\ServiceOrder;
use App\Services\ServiceOrder\CloseServiceOrderWorkflow;
use App\Services\ServiceOrder\DuplicateServiceOrderWorkflow;
use App\Services\ServiceOrder\ServiceOrderService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;
use Mary\Traits\Toast;

class ServiceOrderQueue extends OperationPage
{
    use HasDateFilters, Toast, WithPagination;

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

    public bool $showRowActionModal = false;

    #[Locked]
    public ?int $selectedOrderId = null;

    #[Locked]
    public string $rowAction = '';

    #[Locked]
    public string $selectedOrderNumber = '';

    public function requestRowAction(string $action, int $orderId): void
    {
        abort_unless(in_array($action, ['close', 'cancel', 'duplicate'], true), 403);
        $order = $this->tenantOrder($orderId);
        abort_unless($order, 404);

        if ($action !== 'duplicate' && $order->status !== State::OPEN) {
            $this->error('Esta ação está disponível somente para uma OS aberta.');
            $this->loadOrders();

            return;
        }

        $this->selectedOrderId = $order->id;
        $this->selectedOrderNumber = $order->number;
        $this->rowAction = $action;
        $this->showRowActionModal = true;
    }

    public function confirmRowAction(): void
    {
        abort_unless($this->showRowActionModal && $this->selectedOrderId && in_array($this->rowAction, ['close', 'cancel', 'duplicate'], true), 403);
        $order = $this->tenantOrder($this->selectedOrderId);
        abort_unless($order, 404);

        if ($this->rowAction !== 'duplicate' && $order->status !== State::OPEN) {
            $this->error('A OS não está mais aberta. Atualize a listagem para conferir.');
            $this->showRowActionModal = false;
            $this->loadOrders();

            return;
        }

        if ($this->rowAction === 'duplicate') {
            $workflow = app(DuplicateServiceOrderWorkflow::class);
            $duplicated = $workflow->execute($order, (int) Auth::id());

            if (! $duplicated) {
                $this->error($workflow->getMessageUser());

                return;
            }

            $this->showRowActionModal = false;
            $this->success($workflow->getMessage());
            $this->redirect(ServiceOrderDetail::getUrl(['record' => $duplicated], tenant: Filament::getTenant()), navigate: true);

            return;
        }

        if ($this->rowAction === 'close') {
            $service = app(CloseServiceOrderWorkflow::class);
            $success = $service->execute($order, (int) Auth::id(), false);
        } else {
            $service = app(ServiceOrderService::class);
            $success = $service->cancel($order, (int) Auth::id()) !== null && ! $service->hasError();
        }

        if (! $success) {
            $this->error($service->getMessageUser());

            return;
        }

        $this->showRowActionModal = false;
        $this->selectedOrderId = null;
        $this->selectedOrderNumber = '';
        $this->rowAction = '';
        $this->success($service->getMessage());
        $this->resetPage();
    }

    private function tenantOrder(int $id): ?ServiceOrder
    {
        return ServiceOrder::query()->where('company_id', Filament::getTenant()?->getKey())->whereKey($id)->first();
    }

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
                'equipment:id,name,type,placa,serial_number',
                'items',
                'requisition.items',
                'latestPixPayment',
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
                    ->orWhereHas('equipment', fn ($eq) => $eq->where(fn ($equipment) => $equipment->where('name', 'like', "%{$search}%")->orWhere('placa', 'like', "%{$search}%")->orWhere('serial_number', 'like', "%{$search}%")));
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
                'equipment' => $order->equipment?->identifier ?: '-',
                'technician' => $order->technician?->name ?? 'Sem técnico',
                'status' => $order->status?->description() ?? '-',
                'status_color' => $order->status?->color() ?? 'gray',
                'is_open' => $order->status === State::OPEN,
                'priority' => $order->priority?->description() ?? '-',
                'pix_payment' => $order->latestPixPayment
                    ? ($order->latestPixPayment->status->value === 'paid' ? 'PIX: pagamento confirmado' : 'PIX: '.$order->latestPixPayment->status->description())
                    : null,
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
