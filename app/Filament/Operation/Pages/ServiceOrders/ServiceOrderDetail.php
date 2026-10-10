<?php

namespace App\Filament\Operation\Pages\ServiceOrders;

use App\Enum\ServiceOrder\State;
use App\Filament\Operation\Concerns\HasOperationActions;
use App\Filament\Operation\OperationPage;
use App\Models\Equipment;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\ServiceDiscount\ServiceDiscountService;
use App\Services\ServiceOrder\CloseServiceOrderWorkflow;
use App\Services\ServiceOrder\ServiceOrderService;
use App\Services\ServiceOrderItem\ServiceOrderItemService;
use App\Support\OperationMoney;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Mary\Traits\Toast;

class ServiceOrderDetail extends OperationPage
{
    use HasOperationActions, Toast;

    protected static ?string $title = 'Detalhe da OS';

    protected static ?string $slug = 'ordens/{record}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.operation.pages.service-order-detail';

    public ?array $order = null;

    #[Locked]
    public string $order_id = '';

    public array $formData = [];

    public bool $showServiceModal = false;

    #[Locked]
    public ?int $editingItemId = null;

    public array $serviceData = ['service_id' => null, 'quantity' => 1, 'unit_price' => '', 'discount_percentage' => 0, 'discount_amount' => 0, 'observations' => ''];

    public array $services = [];

    protected function getOperationActions(): array
    {
        $order = $this->tenantOrder();
        $actions = [['label' => 'Voltar', 'icon' => 'o-arrow-left', 'url' => ServiceOrderQueue::getUrl(tenant: Filament::getTenant())]];

        if ($order && in_array($order->status, [State::OPEN, State::CLOSED], true)) {
            $actions[] = ['label' => 'Salvar', 'icon' => 'o-check', 'method' => 'save', 'primary' => true];
        }

        if ($order?->status === State::OPEN) {
            $actions[] = ['label' => 'Encerrar', 'icon' => 'o-check-circle', 'method' => 'close', 'confirm' => true];
            $actions[] = ['label' => 'Cancelar', 'icon' => 'o-x-circle', 'method' => 'cancel', 'confirm' => true];
        }

        return $actions;
    }

    public function mount(int|string $record): void
    {
        $this->order_id = (string) $record;
        $this->loadOrder();
    }

    public function loadOrder(bool $preserveForm = false): void
    {
        $order = $this->tenantOrder()?->load(['customer', 'technician', 'equipment', 'items.service', 'requisition.items']);

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
            'location' => $order->location ?? '-',
            'customer_name' => $order->customer?->name ?? '-',
            'customer_doc' => $order->customer?->document_number ?? '-',
            'technician_name' => $order->technician?->name ?? 'Não atribuído',
            'equipment_name' => $order->equipment?->name ?? '-',
            'equipment_identifier' => $order->equipment?->identifier ?? '-',
            'solution' => $order->solution ?? '',
            'technician_observations' => $order->technician_observations ?? '',
            'customer_observations' => $order->customer_observations ?? '',
            'items_received' => $order->items_received ?? '',
            'general_observations' => $order->general_observations ?? '',
            'items' => $order->items->map(fn ($item): array => [
                'id' => $item->id,
                'name' => $item->service?->name ?? '-',
                'quantity' => number_format((float) $item->quantity, 2, ',', '.'),
                'unit_price' => 'R$ '.number_format((float) $item->unit_price, 2, ',', '.'),
                'total' => 'R$ '.number_format((float) $item->total_amount, 2, ',', '.'),
                'observations' => $item->observations,
            ])->all(),
            'total' => 'R$ '.number_format((float) $order->grand_total_amount, 2, ',', '.'),
            'can_edit' => in_array($order->status, [State::OPEN, State::CLOSED], true),
            'is_open' => $order->status === State::OPEN,
        ];

        if (! $preserveForm) {
            $this->formData = $order->only(['technician_id', 'equipment_id', 'customer_observations', 'items_received', 'general_observations', 'solution', 'technician_observations']);
        }
    }

    #[Computed]
    public function technicians(): array
    {
        return User::query()->whereHas('companies', fn ($query) => $query->where('companies.id', Filament::getTenant()?->getKey()))
            ->orderBy('name')->get(['id', 'name'])->toArray();
    }

    #[Computed]
    public function equipments(): array
    {
        return Equipment::query()->where('company_id', Filament::getTenant()?->getKey())->where('owner_id', $this->tenantOrder()?->customer_id)
            ->orderBy('name')->get()->map(fn ($equipment): array => ['id' => $equipment->id, 'name' => trim($equipment->name.' '.$equipment->identifier)])->all();
    }

    public function save(): void
    {
        $order = $this->tenantOrder();

        if (! $order || ! in_array($order->status, [State::OPEN, State::CLOSED], true)) {
            return;
        }

        $data = $this->validate([
            'formData.technician_id' => ['nullable', 'integer', Rule::in(array_column($this->technicians, 'id'))],
            'formData.equipment_id' => ['nullable', 'integer', Rule::exists('equipments', 'id')->where('company_id', $order->company_id)->where('owner_id', $order->customer_id)->whereNull('deleted_at')],
            'formData.customer_observations' => 'nullable|string',
            'formData.items_received' => 'nullable|string',
            'formData.general_observations' => 'nullable|string',
            'formData.solution' => 'nullable|string',
            'formData.technician_observations' => 'nullable|string',
        ])['formData'];
        $service = app(ServiceOrderService::class);
        $updated = $service->update($order, $data, (int) Auth::id());

        if ($service->hasError() || ! $updated) {
            $this->error($service->getMessageUser());

            return;
        }

        $this->loadOrder();
        $this->success($service->getMessage());
    }

    public function openAddService(): void
    {
        abort_unless($this->tenantOrder()?->status === State::OPEN, 403);
        $this->resetValidation();
        $this->editingItemId = null;
        $this->serviceData = ['service_id' => null, 'quantity' => 1, 'unit_price' => '', 'discount_percentage' => 0, 'discount_amount' => 0, 'observations' => ''];
        $this->searchServices();
        $this->showServiceModal = true;
    }

    public function openEditService(int $itemId): void
    {
        $order = $this->tenantOrder();
        abort_unless($order?->status === State::OPEN, 403);
        $item = $order->items()->find($itemId);
        abort_unless($item, 404);
        $this->resetValidation();
        $this->editingItemId = $item->id;
        $this->serviceData = $item->only(['service_id', 'quantity', 'unit_price', 'discount_percentage', 'discount_amount', 'observations']);
        $this->searchServices();
        $this->showServiceModal = true;
    }

    public function searchServices(string $search = ''): void
    {
        abort_unless(Filament::getTenant(), 403);
        $query = Service::query()->where('company_id', Filament::getTenant()->getKey());
        $records = (clone $query)->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('service_code', 'like', "%{$search}%"))->orderBy('name')->limit(30)->get();

        if ($selected = (clone $query)->find($this->serviceData['service_id'] ?? null)) {
            $records = $records->push($selected)->unique('id');
        }

        $this->services = $records->map(fn ($service): array => ['id' => $service->id, 'name' => "[{$service->service_code}] {$service->name}"])->values()->all();
    }

    public function updatedServiceData(mixed $value, ?string $key = null): void
    {
        $order = $this->tenantOrder();
        abort_unless($order?->status === State::OPEN, 403);

        if ($key === 'service_id') {
            $service = Service::query()->where('company_id', $order->company_id)->find($value);

            if (! $service) {
                return;
            }

            $this->serviceData['unit_price'] = (float) $service->price;
            $discount = app(ServiceDiscountService::class)->resolveAutomaticDiscount(
                companyId: $order->company_id, customerId: $order->customer_id, service: $service,
                quantity: max(0.001, OperationMoney::amount($this->serviceData['quantity'] ?? 1)), unitPrice: (float) $service->price,
            );
            $this->serviceData['discount_percentage'] = $discount['discount_percentage'];
            $this->serviceData['discount_amount'] = $discount['discount_amount'];
        } elseif (in_array($key, ['quantity', 'unit_price', 'discount_percentage', 'discount_amount'], true)) {
            $subtotal = OperationMoney::amount($this->serviceData['quantity']) * OperationMoney::amount($this->serviceData['unit_price']);

            if ($key === 'discount_amount') {
                $this->serviceData['discount_percentage'] = $subtotal > 0 ? round(OperationMoney::amount($value) / $subtotal * 100, 2) : 0;
            } else {
                $this->serviceData['discount_amount'] = round($subtotal * OperationMoney::amount($this->serviceData['discount_percentage']) / 100, 2);
            }
        }
    }

    #[Computed]
    public function minimumServicePrice(): ?string
    {
        $service = Service::query()->where('company_id', Filament::getTenant()?->getKey())->find($this->serviceData['service_id'] ?? null);
        $minimum = (float) ($service?->min_sale_price ?? 0);

        return $minimum > 0 ? 'Preço mínimo após desconto: R$ '.number_format($minimum, 2, ',', '.') : null;
    }

    #[Computed]
    public function serviceTotal(): string
    {
        $total = OperationMoney::amount($this->serviceData['quantity'] ?? 0) * OperationMoney::amount($this->serviceData['unit_price'] ?? 0) - OperationMoney::amount($this->serviceData['discount_amount'] ?? 0);

        return 'R$ '.number_format($total, 2, ',', '.');
    }

    public function saveService(): void
    {
        $order = $this->tenantOrder();
        abort_unless($order?->status === State::OPEN, 403);

        foreach (['quantity', 'unit_price', 'discount_percentage', 'discount_amount'] as $field) {
            $this->serviceData[$field] = OperationMoney::normalize($this->serviceData[$field] ?? null);
        }

        $data = $this->validate([
            'serviceData.service_id' => ['required', 'integer', Rule::exists('services', 'id')->where('company_id', $order->company_id)->whereNull('deleted_at')],
            'serviceData.quantity' => 'required|numeric|min:0.001',
            'serviceData.unit_price' => 'required|numeric|min:0',
            'serviceData.discount_percentage' => 'required|numeric|min:0|max:100',
            'serviceData.discount_amount' => 'required|numeric|min:0',
            'serviceData.observations' => 'nullable|string|max:1000',
        ])['serviceData'];
        $service = app(ServiceOrderItemService::class);

        if ($this->editingItemId) {
            $item = $order->items()->find($this->editingItemId);
            abort_unless($item, 404);
            $result = $service->update($item, $data, (int) Auth::id());
        } else {
            $result = $service->create([...$data, 'service_order_id' => $order->id], (int) Auth::id());
        }

        if ($service->hasError() || ! $result) {
            $this->addError('serviceData.unit_price', $service->getMessageUser());

            return;
        }

        $this->showServiceModal = false;
        $this->loadOrder(preserveForm: true);
        $this->success($service->getMessage());
    }

    public function close(): void
    {
        $order = $this->tenantOrder();

        if (! $order || $order->status !== State::OPEN) {
            return;
        }

        $workflow = app(CloseServiceOrderWorkflow::class);

        if (! $workflow->execute($order, (int) Auth::id(), false)) {
            $this->error($workflow->getMessageUser());

            return;
        }

        $this->loadOrder();
        $this->success($workflow->getMessage());
    }

    public function cancel(): void
    {
        $order = $this->tenantOrder();

        if (! $order || $order->status !== State::OPEN) {
            return;
        }

        $service = app(ServiceOrderService::class);
        $result = $service->cancel($order, (int) Auth::id());

        if ($service->hasError() || ! $result) {
            $this->error($service->getMessageUser());

            return;
        }

        $this->loadOrder();
        $this->success($service->getMessage());
    }

    private function tenantOrder(): ?ServiceOrder
    {
        $tenant = Filament::getTenant();

        return $tenant ? ServiceOrder::query()->where('company_id', $tenant->getKey())->whereKey($this->order_id)->first() : null;
    }
}
