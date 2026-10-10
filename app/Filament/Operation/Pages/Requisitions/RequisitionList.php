<?php

namespace App\Filament\Operation\Pages\Requisitions;

use App\Filament\Operation\Concerns\HasDateFilters;
use App\Filament\Operation\OperationPage;
use App\Models\Requisition;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

class RequisitionList extends OperationPage
{
    use HasDateFilters, WithPagination;

    protected ?LengthAwarePaginator $pagination = null;

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
        $this->restoreDateFilters();
        $this->loadRequisitions();
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

    public function loadRequisitions(): void
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            $this->requisitions = [];
            $this->openCount = 0;
            $this->closedCount = 0;
            $this->allCount = 0;
            $this->pagination = new LengthAwarePaginator([], 0, 15, $this->getPage());

            return;
        }

        $baseQuery = Requisition::query()
            ->where('company_id', $tenant->getKey())
            ->where('status', '!=', 'cancelled')
            ->with(['customer:id,name', 'serviceOrder:id,number', 'equipment:id,name'])
            ->withCount('items');

        $this->applyDateRange($baseQuery, 'sale_date');

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

        $this->pagination = $query
            ->orderByDesc('sale_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'page', $this->getPage())
            ->through(fn (Requisition $req) => [
                'id' => $req->id,
                'number' => $req->number,
                'customer' => $req->customer?->name ?? '-',
                'service_order' => $req->serviceOrder?->number ?? '-',
                'equipment' => $req->equipment?->name ?? '-',
                'status' => $req->status?->description() ?? '-',
                'status_value' => $req->status?->value ?? '',
                'sale_date' => $req->sale_date?->format('d/m/Y') ?? '-',
                'total' => 'R$ '.number_format((float) $req->total_amount, 2, ',', '.'),
                'items_count' => $req->items_count,
                'url' => RequisitionDetail::getUrl(
                    ['record' => $req->id],
                    tenant: $tenant,
                ),
            ]);
        $this->requisitions = $this->pagination->items();
    }

    protected function refreshList(): void
    {
        $this->loadRequisitions();
    }

    protected function getViewData(): array
    {
        if (! $this->pagination) {
            $this->loadRequisitions();
        }

        return ['pagination' => $this->pagination];
    }
}
