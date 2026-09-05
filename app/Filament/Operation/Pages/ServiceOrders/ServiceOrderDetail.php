<?php

namespace App\Filament\Operation\Pages\ServiceOrders;

use App\Enum\ServiceOrder\State;
use App\Models\ServiceOrder;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrder\CloseServiceOrderWorkflow;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ServiceOrderDetail extends Page
{
    protected static ?string $title = 'Detalhe da OS';

    protected static ?string $slug = 'ordens/{record}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.operation.pages.service-order-detail';

    public ?array $order = null;

    public string $order_id = '';

    public bool $saving = false;

    public array $formData = [];

    public function mount(int|string $record): void
    {
        $this->order_id = (string) $record;
        $this->loadOrder();
    }

    public function loadOrder(): void
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            $this->order = null;

            return;
        }

        $order = ServiceOrder::query()
            ->where('company_id', $tenant->getKey())
            ->where('id', $this->order_id)
            ->with([
                'customer:id,name,document_number',
                'technician:id,name',
                'equipment:id,name,placa,serial_number',
                'items.service:id,name,service_code,price',
                'requisition.items',
            ])
            ->first();

        if (! $order) {
            $this->order = null;

            return;
        }

        $this->order = [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status?->value,
            'status_label' => $order->status?->description() ?? '-',
            'status_color' => $order->status?->color() ?? 'gray',
            'priority' => $order->priority?->description() ?? '-',
            'type' => $order->type?->description() ?? '-',
            'order_date' => $order->order_date?->format('d/m/Y') ?? '-',
            'scheduled_date' => $order->scheduled_date?->format('d/m/Y') ?? '-',
            'location' => $order->location ?? '-',
            'customer_name' => $order->customer?->name ?? '-',
            'customer_doc' => $order->customer?->document_number ?? '-',
            'technician_name' => $order->technician?->name ?? 'Não atribuído',
            'equipment_name' => $order->equipment?->name ?? '-',
            'equipment_identifier' => $order->equipment?->placa ?? $order->equipment?->serial_number ?? '-',
            'solution' => $order->solution ?? '',
            'technician_observations' => $order->technician_observations ?? '',
            'customer_observations' => $order->customer_observations ?? '',
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->service?->name ?? '-',
                'quantity' => number_format((float) $item->quantity, 2, ',', '.'),
                'unit_price' => 'R$ '.number_format((float) $item->unit_price, 2, ',', '.'),
                'total' => 'R$ '.number_format((float) $item->total_amount, 2, ',', '.'),
            ])->toArray(),
            'total' => 'R$ '.number_format((float) $order->grand_total_amount, 2, ',', '.'),
            'can_edit' => in_array($order->status, [State::OPEN, State::CLOSED], true),
            'is_open' => $order->status === State::OPEN,
            'is_closed' => $order->status === State::CLOSED,
            'list_url' => ServiceOrderQueue::getUrl(tenant: $tenant),
        ];

        $this->formData = [
            'solution' => $order->solution ?? '',
            'technician_observations' => $order->technician_observations ?? '',
        ];
    }

    public function save(): void
    {
        $tenant = Filament::getTenant();

        if (! $tenant || ! $this->order) {
            return;
        }

        $order = ServiceOrder::query()
            ->where('company_id', $tenant->getKey())
            ->where('id', $this->order_id)
            ->first();

        if (! $order || ! in_array($order->status, [State::OPEN, State::CLOSED], true)) {
            return;
        }

        $this->saving = true;

        $service = app(ServiceOrderService::class);
        $updated = $service->update($order, [
            'solution' => $this->formData['solution'] ?? '',
            'technician_observations' => $this->formData['technician_observations'] ?? '',
        ], (int) Auth::id());

        $this->saving = false;

        if ($service->hasError() || $updated === null) {
            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode(),
            );

            return;
        }

        $this->loadOrder();

        notify::success(message: $service->getMessage());
    }

    public function close(): void
    {
        $order = $this->tenantOrder();

        if (! $order || $order->status !== State::OPEN) {
            return;
        }

        $workflow = app(CloseServiceOrderWorkflow::class);
        $closed = $workflow->execute($order, (int) Auth::id(), false);

        if (! $closed) {
            notify::error(
                message: $workflow->getMessageUser(),
                errorCode: $workflow->getErrorCode(),
            );

            return;
        }

        notify::success(message: $workflow->getMessage());
        $this->loadOrder();
    }

    public function cancel(): void
    {
        $order = $this->tenantOrder();

        if (! $order || $order->status !== State::OPEN) {
            return;
        }

        $service = app(ServiceOrderService::class);
        $cancelled = $service->cancel($order, (int) Auth::id());

        if ($service->hasError() || $cancelled === null) {
            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode(),
            );

            return;
        }

        notify::success(message: $service->getMessage());
        $this->loadOrder();
    }

    private function tenantOrder(): ?ServiceOrder
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            return null;
        }

        return ServiceOrder::query()
            ->where('company_id', $tenant->getKey())
            ->whereKey($this->order_id)
            ->first();
    }
}
