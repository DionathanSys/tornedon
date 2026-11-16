<?php

namespace App\Filament\Operation\Pages\Requisitions;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Enum\Requisition\Status;
use App\Enum\ServiceOrder\State;
use App\Filament\Operation\Concerns\HasOperationActions;
use App\Filament\Operation\Concerns\HasOperationProducts;
use App\Filament\Operation\OperationModules;
use App\Filament\Operation\OperationPage;
use App\Filament\Operation\Pages\ServiceOrders\ServiceOrderDetail;
use App\Models\Equipment;
use App\Models\Requisition;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Requisition\RequisitionService;
use App\Support\Financial\PaymentTerms;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Mary\Traits\Toast;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RequisitionDetail extends OperationPage
{
    use HasOperationActions, HasOperationProducts, Toast;

    protected static ?string $title = 'Detalhe da Requisição';

    protected static ?string $slug = 'requisicoes/{record}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.operation.pages.requisition-detail';

    public ?array $requisition = null;

    #[Locked]
    public string $record_id = '';

    public array $formData = [];

    protected function getOperationActions(): array
    {
        $actions = [['label' => 'Voltar', 'icon' => 'o-arrow-left', 'url' => RequisitionList::getUrl(tenant: Filament::getTenant())]];
        $requisition = $this->tenantRequisition();

        if (! $requisition) {
            return $actions;
        }

        if ($this->canEditRequisition($requisition)) {
            $actions[] = ['label' => 'Salvar', 'icon' => 'o-check', 'method' => 'save', 'primary' => true];
        }

        if ($requisition->status === Status::OPEN) {
            if (! $requisition->service_order_id) {
                $actions[] = ['label' => 'Encerrar', 'icon' => 'o-check-circle', 'method' => 'close', 'confirm' => true];
            }
            $actions[] = ['label' => 'Cancelar', 'icon' => 'o-x-circle', 'method' => 'cancel', 'confirm' => true];
        }

        $actions[] = ['label' => 'Baixar PDF', 'icon' => 'o-arrow-down-tray', 'method' => 'downloadPdf', 'more' => true];

        if (in_array($requisition->status, [Status::CLOSED, Status::CANCELLED], true) && ! $requisition->invoice_id && $this->linkedOrderAllowsEditing($requisition)) {
            $actions[] = ['label' => 'Reabrir', 'icon' => 'o-arrow-path', 'method' => 'reopen', 'confirm' => true, 'more' => true];
        }

        if ($requisition->status === Status::CLOSED && ! $requisition->service_order_id && ! $requisition->invoice_id) {
            $actions[] = ['label' => 'Faturar', 'icon' => 'o-document-text', 'method' => 'invoiceRequisition', 'confirm' => true, 'more' => true];
        }

        return $actions;
    }

    public function mount(int|string $record): void
    {
        $this->record_id = (string) $record;
        $this->loadRequisition();
    }

    public function loadRequisition(bool $preserveForm = false): void
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            $this->requisition = null;

            return;
        }

        $req = Requisition::query()
            ->where('company_id', $tenant->getKey())
            ->where('id', $this->record_id)
            ->with([
                'customer:id,name,document_number',
                'serviceOrder:id,number,status,company_id',
                'equipment:id,name,placa,serial_number',
                'items.product:id,name,product_code',
                'salesperson:id,name',
                'invoice:id,number',
            ])
            ->first();

        if (! $req) {
            $this->requisition = null;

            return;
        }

        $this->requisition = [
            'id' => $req->id,
            'number' => $req->number,
            'status' => $req->status?->description() ?? '-',
            'status_value' => $req->status?->value ?? '',
            'sale_date' => $req->sale_date?->format('d/m/Y') ?? '-',
            'customer_name' => $req->customer?->name ?? '-',
            'customer_doc' => $req->customer?->document_number ?? '-',
            'service_order_number' => $req->serviceOrder?->number ?? '-',
            'service_order_url' => $req->service_order_id && $req->serviceOrder?->company_id === $req->company_id && OperationModules::allows('service_orders')
                ? ServiceOrderDetail::getUrl(['record' => $req->service_order_id], tenant: $tenant) : null,
            'equipment_name' => $req->equipment?->name ?? '-',
            'equipment_identifier' => $req->equipment?->placa ?? $req->equipment?->serial_number ?? '-',
            'observations' => $req->observations ?? '',
            'total' => 'R$ '.number_format((float) $req->total_amount, 2, ',', '.'),
            'is_open' => $req->status === Status::OPEN,
            'can_edit' => $this->canEditRequisition($req),
            'payment_method' => $req->payment_method?->description() ?? 'Não definida',
            'payment_condition' => PaymentTerms::description($req->payment_condition, $req->payment_condition_details),
            'delivery_date' => $req->delivery_date?->format('d/m/Y') ?? '-',
            'delivery_address' => $req->delivery_address ?? '',
            'salesperson_name' => $req->salesperson?->name ?? '-',
            'invoice_number' => $req->invoice?->number,
            'items' => $req->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->product?->name ?? '-',
                'code' => $item->product?->product_code ?? '-',
                'quantity' => number_format((float) $item->quantity, 3, ',', '.'),
                'unit' => $item->unit_of_measure ?? '-',
                'unit_price' => 'R$ '.number_format((float) $item->unit_price, 2, ',', '.'),
                'total' => 'R$ '.number_format((float) $item->total_amount, 2, ',', '.'),
                'stock_consumed' => $item->stock_consumed ?? false,
                'discount' => 'R$ '.number_format((float) $item->discount_amount, 2, ',', '.'),
                'observations' => $item->observations,
            ])->toArray(),
            'list_url' => RequisitionList::getUrl(tenant: $tenant),
        ];

        if (! $preserveForm) {
            $this->formData = [
                'equipment_id' => $req->equipment_id,
                'salesperson_id' => $req->salesperson_id,
                'payment_method' => $req->payment_method?->value,
                'payment_condition' => $req->payment_condition?->value,
                'payment_condition_details' => $req->payment_condition_details,
                'delivery_date' => $req->delivery_date?->format('Y-m-d'),
                'delivery_address' => $req->delivery_address,
                'observations' => $req->observations,
            ];
        }
    }

    private function linkedOrderAllowsEditing(Requisition $requisition): bool
    {
        return ! $requisition->service_order_id || ($requisition->serviceOrder?->company_id === $requisition->company_id && $requisition->serviceOrder?->status === State::OPEN);
    }

    private function canEditRequisition(Requisition $requisition): bool
    {
        return $requisition->state()->canEdit() && ! $requisition->invoice_id && $this->linkedOrderAllowsEditing($requisition);
    }

    protected function editableProductDocument(): Requisition
    {
        $requisition = $this->tenantRequisition();
        abort_unless($requisition, 404);
        abort_unless($this->canEditRequisition($requisition), 403);

        return $requisition;
    }

    protected function productItems(): HasMany
    {
        return $this->editableProductDocument()->items();
    }

    protected function resolveProductRequisition(Requisition|ServiceOrder $document): Requisition
    {
        return $this->editableProductDocument();
    }

    protected function refreshProductDocument(): void
    {
        $this->loadRequisition(preserveForm: true);
    }

    #[Computed]
    public function equipments(): array
    {
        return Equipment::query()->where('company_id', Filament::getTenant()?->getKey())
            ->where('owner_id', $this->tenantRequisition()?->customer_id)->orderBy('name')->get()
            ->map(fn ($equipment): array => ['id' => $equipment->id, 'name' => trim($equipment->name.' '.$equipment->identifier)])->all();
    }

    #[Computed]
    public function salespeople(): array
    {
        return User::query()->whereHas('companies', fn ($query) => $query->where('companies.id', Filament::getTenant()?->getKey())->where('company_user.is_active', true))
            ->orderBy('name')->get(['id', 'name'])->toArray();
    }

    public function updatedFormData(mixed $value, ?string $key = null): void
    {
        if ($key === 'payment_condition') {
            $this->formData['payment_condition_details'] = $value === Condition::CUSTOM->value
                ? ['installments' => 1, 'first_due_days' => 0, 'interval_days' => 30]
                : null;
        }
    }

    public function save(): bool
    {
        $requisition = $this->editableProductDocument();
        $data = $this->validate([
            'formData.equipment_id' => ['nullable', 'integer', Rule::exists('equipments', 'id')->where('company_id', $requisition->company_id)->where('owner_id', $requisition->customer_id)->whereNull('deleted_at')],
            'formData.salesperson_id' => ['nullable', 'integer', Rule::in(array_column($this->salespeople, 'id'))],
            'formData.payment_method' => ['nullable', Rule::enum(Method::class)],
            'formData.payment_condition' => ['nullable', Rule::enum(Condition::class)],
            ...PaymentTerms::rules('formData.payment_condition', 'formData.payment_condition_details'),
            'formData.delivery_date' => ['nullable', 'date', 'after_or_equal:'.$requisition->sale_date->format('Y-m-d')],
            'formData.delivery_address' => 'nullable|string|max:500',
            'formData.observations' => 'nullable|string|max:1000',
        ])['formData'];
        $data['payment_condition_details'] = PaymentTerms::details(Condition::tryFrom($data['payment_condition'] ?? ''), $data['payment_condition_details'] ?? null);
        $service = app(RequisitionService::class);
        $result = $service->update($requisition, $data, (int) Auth::id());

        if (! $result || $service->hasError()) {
            $this->error($service->getMessageUser());

            return false;
        }

        $this->loadRequisition();
        $this->success($service->getMessage());

        return true;
    }

    public function reopen(): void
    {
        $requisition = $this->tenantRequisition();
        abort_unless($requisition, 404);
        abort_unless(in_array($requisition->status, [Status::CLOSED, Status::CANCELLED], true) && ! $requisition->invoice_id && $this->linkedOrderAllowsEditing($requisition), 403);
        $service = app(RequisitionService::class);

        if (! $service->reopen($requisition, (int) Auth::id()) || $service->hasError()) {
            $this->error($service->getMessageUser());

            return;
        }

        $this->loadRequisition();
        $this->success($service->getMessage());
    }

    public function invoiceRequisition(): void
    {
        $requisition = $this->tenantRequisition();
        abort_unless($requisition, 404);
        abort_unless($requisition->status === Status::CLOSED && ! $requisition->service_order_id && ! $requisition->invoice_id, 403);
        $service = app(RequisitionService::class);

        if (! $service->invoice($requisition, (int) Auth::id()) || $service->hasError()) {
            $this->error($service->getMessageUser());

            return;
        }

        $this->loadRequisition();
        $this->success($service->getMessage());
    }

    public function downloadPdf(): ?StreamedResponse
    {
        $requisition = $this->tenantRequisition();
        abort_unless($requisition, 404);
        $service = app(RequisitionService::class);
        $pdf = $service->pdf($requisition, (int) Auth::id());

        if (! $pdf || $service->hasError()) {
            $this->error($service->getMessageUser());

            return null;
        }

        return response()->streamDownload(fn () => print (base64_decode($pdf)), 'requisicao-'.$requisition->number.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function close(): void
    {
        $requisition = $this->tenantRequisition();

        if (! $requisition || $requisition->status !== Status::OPEN) {
            return;
        }

        abort_if((bool) $requisition->service_order_id, 403);

        if (! $this->save()) {
            return;
        }

        $service = app(RequisitionService::class);
        $closed = $service->close($requisition, (int) Auth::id(), false);

        if ($service->hasError() || $closed === null) {
            $this->error($service->getMessageUser());

            return;
        }

        $this->success($service->getMessage());
        $this->loadRequisition();
    }

    public function cancel(): void
    {
        $requisition = $this->tenantRequisition();

        if (! $requisition || $requisition->status !== Status::OPEN) {
            return;
        }

        $service = app(RequisitionService::class);
        $cancelled = $service->cancel($requisition, (int) Auth::id());

        if ($service->hasError() || $cancelled === null) {
            $this->error($service->getMessageUser());

            return;
        }

        $this->success($service->getMessage());
        $this->loadRequisition();
    }

    private function tenantRequisition(): ?Requisition
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            return null;
        }

        return Requisition::query()
            ->where('company_id', $tenant->getKey())
            ->whereKey($this->record_id)
            ->first();
    }
}
