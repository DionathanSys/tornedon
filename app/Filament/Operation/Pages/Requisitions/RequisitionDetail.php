<?php

namespace App\Filament\Operation\Pages\Requisitions;

use App\Enum\Requisition\Status;
use App\Models\Requisition;
use App\Notification\NotifyService as notify;
use App\Services\Requisition\RequisitionService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class RequisitionDetail extends Page
{
    protected static ?string $title = 'Detalhe da Requisição';

    protected static ?string $slug = 'requisicoes/{record}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.operation.pages.requisition-detail';

    public ?array $requisition = null;

    public string $record_id = '';

    public function mount(int|string $record): void
    {
        $this->record_id = (string) $record;
        $this->loadRequisition();
    }

    public function loadRequisition(): void
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
                'serviceOrder:id,number,status',
                'equipment:id,name,placa,serial_number',
                'items.product:id,name,product_code',
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
            'equipment_name' => $req->equipment?->name ?? '-',
            'equipment_identifier' => $req->equipment?->placa ?? $req->equipment?->serial_number ?? '-',
            'observations' => $req->observations ?? '',
            'total' => 'R$ '.number_format((float) $req->total_amount, 2, ',', '.'),
            'is_open' => $req->status === Status::OPEN,
            'items' => $req->items->map(fn ($item) => [
                'name' => $item->product?->name ?? '-',
                'code' => $item->product?->product_code ?? '-',
                'quantity' => number_format((float) $item->quantity, 3, ',', '.'),
                'unit' => $item->unit_of_measure ?? '-',
                'unit_price' => 'R$ '.number_format((float) $item->unit_price, 2, ',', '.'),
                'total' => 'R$ '.number_format((float) $item->total_amount, 2, ',', '.'),
                'stock_consumed' => $item->stock_consumed ?? false,
            ])->toArray(),
            'list_url' => RequisitionList::getUrl(tenant: $tenant),
        ];
    }

    public function getStatusBadgeClass(string $status): string
    {
        return match ($status) {
            'open' => 'op-badge--info',
            'closed' => 'op-badge--success',
            'invoiced' => 'op-badge--warning',
            'cancelled' => 'op-badge--danger',
            default => 'op-badge--gray',
        };
    }

    public function close(): void
    {
        $requisition = $this->tenantRequisition();

        if (! $requisition || $requisition->status !== Status::OPEN) {
            return;
        }

        $service = app(RequisitionService::class);
        $closed = $service->close($requisition, (int) Auth::id(), false);

        if ($service->hasError() || $closed === null) {
            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode(),
            );

            return;
        }

        notify::success(message: $service->getMessage());
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
            notify::error(
                message: $service->getMessageUser(),
                errorCode: $service->getErrorCode(),
            );

            return;
        }

        notify::success(message: $service->getMessage());
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
