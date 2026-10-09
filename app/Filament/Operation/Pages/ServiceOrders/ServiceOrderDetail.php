<?php

namespace App\Filament\Operation\Pages\ServiceOrders;

use App\Enum\ServiceOrder\State;
use App\Filament\Clusters\Sales\Resources\Components\ItemValueGroup;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\RelationManagers\Schemas\ServiceItemForm;
use App\Models\Equipment;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\User;
use App\Notification\NotifyService as notify;
use App\Services\ServiceOrder\CloseServiceOrderWorkflow;
use App\Services\ServiceOrder\ServiceOrderService;
use App\Services\ServiceOrderItem\ServiceOrderItemService;
use App\Traits\ParsesMoneyValues;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ServiceOrderDetail extends Page
{
    use ParsesMoneyValues;

    protected static ?string $title = 'Detalhe da OS';

    protected static ?string $slug = 'ordens/{record}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.operation.pages.service-order-detail';

    public ?array $order = null;

    public string $order_id = '';

    public bool $saving = false;

    public array $formData = [];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')->label('Voltar')->icon('heroicon-o-arrow-left')->color('gray')
                ->url(fn (): string => ServiceOrderQueue::getUrl(tenant: Filament::getTenant())),
            Action::make('save')->label('Salvar')->icon('heroicon-o-check')
                ->visible(fn (): bool => in_array($this->tenantOrder()?->status, [State::OPEN, State::CLOSED], true))
                ->action(fn () => $this->save()),
            $this->addServiceAction(),
            Action::make('close')->label('Encerrar')->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn (): bool => $this->tenantOrder()?->status === State::OPEN)
                ->requiresConfirmation()->modalHeading('Encerrar esta ordem de serviço?')
                ->action(fn () => $this->close()),
            Action::make('cancel')->label('Cancelar')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (): bool => $this->tenantOrder()?->status === State::OPEN)
                ->requiresConfirmation()->modalHeading('Cancelar esta ordem de serviço?')
                ->action(fn () => $this->cancel()),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $order = $this->tenantOrder();
        $companyId = Filament::getTenant()?->getKey();

        return $schema->statePath('formData')->components([
            Section::make('Responsáveis e equipamento')->schema([
                Select::make('technician_id')
                    ->label('Técnico')->searchable()->preload()
                    ->options(fn (): array => User::query()
                        ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->in(fn (): array => User::query()
                        ->whereHas('companies', fn ($query) => $query->where('companies.id', $companyId))
                        ->pluck('users.id')->all()),
                Select::make('equipment_id')
                    ->label('Equipamento')->searchable()->preload()
                    ->options(fn (): array => Equipment::query()
                        ->where('company_id', $companyId)->where('owner_id', $order?->customer_id)
                        ->orderBy('name')->get()
                        ->mapWithKeys(fn (Equipment $equipment): array => [$equipment->id => trim($equipment->name.' '.$equipment->identifier)])
                        ->all())
                    ->rules([Rule::exists('equipments', 'id')->where('company_id', $companyId)
                        ->where('owner_id', $order?->customer_id)->whereNull('deleted_at')]),
            ])->columns(2),
            Section::make('Registro do Atendimento')->collapsible()->collapsed()->schema([
                Textarea::make('customer_observations')->label('Observações do Cliente')->rows(3),
                Textarea::make('items_received')->label('Itens recebidos')->rows(3),
                Textarea::make('general_observations')->label('Observações gerais')->rows(3),
                Textarea::make('solution')->label('Solução Aplicada')->rows(3),
                Textarea::make('technician_observations')->label('Observações do Técnico')->rows(3),
            ]),
        ]);
    }

    public function addServiceAction(): Action
    {
        return Action::make('addService')->label('Adicionar serviço')->icon('heroicon-o-plus')
            ->visible(fn (): bool => $this->tenantOrder()?->status === State::OPEN)
            ->schema($this->serviceItemSchema())
            ->action(function (array $data, Action $action): void {
                $this->persistServiceItem($data, $action);
            });
    }

    public function editServiceAction(): Action
    {
        return Action::make('editService')->label('Editar serviço')->icon('heroicon-o-pencil-square')
            ->visible(fn (): bool => $this->tenantOrder()?->status === State::OPEN)
            ->schema($this->serviceItemSchema())
            ->fillForm(function (array $arguments): array {
                $item = $this->tenantOrder()?->items()->with('service')->find($arguments['item'] ?? null);
                abort_unless($item, 404);

                return [
                    'service_id' => $item->service_id,
                    'item' => ['min_sale_price' => (float) ($item->service?->min_sale_price ?? 0)],
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'discount_percentage' => $item->discount_percentage,
                    'discount_amount' => $item->discount_amount,
                    'total_amount' => $item->total_amount,
                    'observations' => $item->observations,
                ];
            })
            ->action(function (array $data, array $arguments, Action $action): void {
                $item = $this->tenantOrder()?->items()->find($arguments['item'] ?? null);
                abort_unless($item, 404);
                $this->persistServiceItem($data, $action, $item);
            });
    }

    private function serviceItemSchema(): array
    {
        $values = ItemValueGroup::make([
            'serviceIdField' => 'service_id',
            'preserveDiscountOnValueChange' => true,
            'enforceEffectiveMinSalePrice' => true,
        ]);

        foreach ($values->getDefaultChildComponents() as $field) {
            if ($field->getName() === 'quantity') {
                $field->numeric(false)->rule('numeric')->type('text')->inputMode('decimal')->minValue(0.001)
                    ->mutateStateForValidationUsing(fn ($state): float => self::parseMoneyValue($state))
                    ->dehydrateStateUsing(fn ($state): float => self::parseMoneyValue($state));
            }
        }

        return [
            Select::make('service_id')->label('Serviço')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Service::query()
                    ->where('company_id', Filament::getTenant()?->getKey())
                    ->where(fn ($query) => $query->where('name', 'like', "%{$search}%")
                        ->orWhere('service_code', 'like', "%{$search}%"))
                    ->limit(30)->get()->mapWithKeys(fn (Service $service): array => [$service->id => "[{$service->service_code}] {$service->name}"])->all())
                ->getOptionLabelUsing(fn ($value): ?string => Service::query()
                    ->where('company_id', Filament::getTenant()?->getKey())->find($value)?->name)
                ->rules([Rule::exists('services', 'id')->where('company_id', Filament::getTenant()?->getKey())->whereNull('deleted_at')])
                ->live()->afterStateUpdated(function (Set $set, Get $get, $state): void {
                    ServiceItemForm::syncServiceById($set, $get, $state, $this->tenantOrder());
                }),
            Hidden::make('item.min_sale_price')->saved(false)->default(0),
            $values,
            Textarea::make('observations')->label('Observações')->maxLength(1000),
        ];
    }

    private function persistServiceItem(array $data, Action $action, ?ServiceOrderItem $item = null): void
    {
        $order = $this->tenantOrder();
        abort_unless($order && $order->status === State::OPEN, 403);
        abort_unless(Service::query()->where('company_id', $order->company_id)->whereKey($data['service_id'])->exists(), 404);

        foreach (['quantity', 'unit_price', 'discount_percentage', 'discount_amount'] as $field) {
            $data[$field] = self::parseMoneyValue($data[$field] ?? 0);
        }

        $service = app(ServiceOrderItemService::class);
        $result = $item
            ? $service->update($item, $data, (int) Auth::id())
            : $service->create([...$data, 'service_order_id' => $order->id], (int) Auth::id());

        if ($service->hasError() || ! $result) {
            notify::error(message: $service->getMessageUser(), errorCode: $service->getErrorCode());
            $action->halt();

            return;
        }

        notify::success(message: $service->getMessage());
        $this->loadOrder(preserveForm: true);
    }

    public function mount(int|string $record): void
    {
        $this->order_id = (string) $record;
        $this->loadOrder();
    }

    public function loadOrder(bool $preserveForm = false): void
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
            'items_received' => $order->items_received ?? '',
            'general_observations' => $order->general_observations ?? '',
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->service?->name ?? '-',
                'quantity' => number_format((float) $item->quantity, 2, ',', '.'),
                'unit_price' => 'R$ '.number_format((float) $item->unit_price, 2, ',', '.'),
                'total' => 'R$ '.number_format((float) $item->total_amount, 2, ',', '.'),
                'observations' => $item->observations,
            ])->toArray(),
            'total' => 'R$ '.number_format((float) $order->grand_total_amount, 2, ',', '.'),
            'can_edit' => in_array($order->status, [State::OPEN, State::CLOSED], true),
            'is_open' => $order->status === State::OPEN,
            'is_closed' => $order->status === State::CLOSED,
            'list_url' => ServiceOrderQueue::getUrl(tenant: $tenant),
        ];

        if ($preserveForm) {
            return;
        }

        $this->formData = [
            'technician_id' => $order->technician_id,
            'equipment_id' => $order->equipment_id,
            'customer_observations' => $order->customer_observations ?? '',
            'items_received' => $order->items_received ?? '',
            'general_observations' => $order->general_observations ?? '',
            'solution' => $order->solution ?? '',
            'technician_observations' => $order->technician_observations ?? '',
        ];
        $this->form->fill($this->formData);
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

        $data = $this->form->getState();
        $this->saving = true;
        $service = app(ServiceOrderService::class);
        $updated = $service->update($order, $data, (int) Auth::id());

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
