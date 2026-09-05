<?php

namespace App\Filament\Operation\Pages\Requisitions;

use App\Models\Requisition;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class RequisitionList extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::ClipboardDocument;

    protected static ?string $navigationLabel = 'Requisições';

    protected static ?string $title = 'Requisições';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'requisicoes';

    protected string $view = 'filament.operation.pages.requisition-list';

    public string $activeTab = 'open';

    public ?string $search = '';

    public array $requisitions = [];

    public int $openCount = 0;

    public int $closedCount = 0;

    public int $allCount = 0;

    public function mount(): void
    {
        $this->loadRequisitions();
    }

    public function updatedActiveTab(): void
    {
        $this->loadRequisitions();
    }

    public function updatedSearch(): void
    {
        $this->loadRequisitions();
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['open', 'closed', 'all'], true)) {
            return;
        }

        $this->activeTab = $tab;
        $this->loadRequisitions();
    }

    public function loadRequisitions(): void
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            $this->requisitions = [];
            $this->openCount = 0;
            $this->closedCount = 0;
            $this->allCount = 0;

            return;
        }

        $baseQuery = Requisition::query()
            ->where('company_id', $tenant->getKey())
            ->where('status', '!=', 'cancelled')
            ->with(['customer:id,name', 'serviceOrder:id,number', 'equipment:id,name']);

        $this->openCount = (clone $baseQuery)->where('status', 'open')->count();
        $this->closedCount = (clone $baseQuery)->where('status', 'closed')->count();
        $this->allCount = (clone $baseQuery)->count();

        $query = clone $baseQuery;

        if ($this->activeTab === 'open') {
            $query->where('status', 'open');
        } elseif ($this->activeTab === 'closed') {
            $query->where('status', 'closed');
        }

        if (filled($this->search)) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('serviceOrder', fn ($soq) => $soq->where('number', 'like', "%{$search}%"));
            });
        }

        $this->requisitions = $query
            ->orderByDesc('sale_date')
            ->orderByDesc('created_at')
            ->limit(60)
            ->get()
            ->map(fn (Requisition $req) => [
                'id' => $req->id,
                'number' => $req->number,
                'customer' => $req->customer?->name ?? '-',
                'service_order' => $req->serviceOrder?->number ?? '-',
                'equipment' => $req->equipment?->name ?? '-',
                'status' => $req->status?->description() ?? '-',
                'status_value' => $req->status?->value ?? '',
                'sale_date' => $req->sale_date?->format('d/m/Y') ?? '-',
                'total' => 'R$ '.number_format((float) $req->total_amount, 2, ',', '.'),
                'items_count' => $req->items()->count(),
                'url' => RequisitionDetail::getUrl(
                    ['record' => $req->id],
                    tenant: $tenant,
                ),
            ])
            ->toArray();
    }

    public function getStatusBadgeClass(string $status): string
    {
        return match ($status) {
            'open' => 'op-card__badge--info',
            'closed' => 'op-card__badge--success',
            'invoiced' => 'op-card__badge--warning',
            default => 'op-card__badge--gray',
        };
    }
}
