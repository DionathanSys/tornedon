<?php

namespace App\Filament\Operation;

use App\Filament\Operation\Concerns\HasDateFilters;
use App\Filament\Operation\Concerns\HasModulePayment;
use App\Filament\Operation\Concerns\HasOperationFeedback;
use App\Filament\Operation\Concerns\HasOperationResourcePage;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

abstract class ModuleListPage extends Page
{
    use HasDateFilters, HasModulePayment, HasOperationFeedback, HasOperationResourcePage, WithPagination;

    protected string $view = 'filament.operation.resources.list';

    public string $search = '';

    public string $activeTab = 'open';

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deleteRecordId = null;

    public function moduleKey(): string
    {
        return OperationModules::forPage(static::class);
    }

    public function mount(): void
    {
        $this->restoreDateFilters();
    }

    public function getTitle(): string
    {
        return OperationResourceSupport::definition($this->moduleKey())['title'];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['open', 'closed', 'all'], true)) {
            $this->activeTab = $tab;
            $this->resetPage();
        }
    }

    protected function refreshList(): void
    {
        // Records and counts are queried from the applied filters on each render.
    }

    protected function baseQuery(): Builder
    {
        $module = $this->moduleKey();
        $definition = OperationResourceSupport::definition($module);
        $query = OperationResourceSupport::query($module)->with($definition['relation']);
        if ($module === 'production_requests') {
            $query->with(['items', 'createdBy']);
        } elseif ($module === 'cash_movements') {
            $query->with('counterpartyPartner');
        }
        $this->applyDateRange($query, $definition['date']);
        if (filled($this->search)) {
            $search = '%'.$this->search.'%';
            $query->where(function (Builder $query) use ($search, $module, $definition): void {
                $query->where('manual_counterparty_name', 'like', $search)
                    ->orWhere($module === 'production_requests' ? 'number' : 'description', 'like', $search)
                    ->orWhereHas($definition['relation'], fn (Builder $q) => $q->where('name', 'like', $search));
                if (str_starts_with($module, 'account_')) {
                    $query->orWhere('document_number', 'like', $search);
                }
            });
        }

        return $query;
    }

    public function getTabsProperty(): array
    {
        $definition = OperationResourceSupport::definition($this->moduleKey());
        $field = $this->moduleKey() === 'cash_movements' ? 'direction' : 'status';

        return [
            'open' => [$definition['tabs'][0], $this->baseQuery()->whereIn($field, $definition['pending'])->count()],
            'closed' => [$definition['tabs'][1], $this->baseQuery()->where($field, $definition['settled'])->count()],
            'all' => ['Todos', $this->baseQuery()->count()],
        ];
    }

    public function getRecordsProperty(): LengthAwarePaginator
    {
        $module = $this->moduleKey();
        $definition = OperationResourceSupport::definition($module);
        $field = $module === 'cash_movements' ? 'direction' : 'status';

        return $this->baseQuery()
            ->when($this->activeTab === 'open', fn (Builder $q) => $q->whereIn($field, $definition['pending']))
            ->when($this->activeTab === 'closed', fn (Builder $q) => $q->where($field, $definition['settled']))
            ->orderBy($definition['date'], str_starts_with($module, 'account_') ? 'asc' : 'desc')->orderByDesc('id')->paginate(15)
            ->through(function ($record) use ($module, $definition, $field): array {
                $state = $record->{$field};
                $amount = $module === 'production_requests' ? $record->total_amount : ($module === 'cash_movements' ? $record->amount : $record->due_amount);

                return [
                    'id' => $record->id, 'url' => OperationResourceSupport::resource($module)::getUrl('edit', ['record' => $record->id]),
                    'title' => $module === 'production_requests' ? 'Pedido #'.$record->number : ($record->document_number ?: ($record->description ?: 'Registro #'.$record->id)),
                    'subtitle' => $record->counterparty_label, 'status' => $state?->description() ?? '-', 'color' => $state && method_exists($state, 'color') ? $state->color() : ($state?->value === 'inflow' ? 'success' : 'danger'),
                    'amount' => OperationResourceSupport::money($amount), 'details' => array_filter([$record->{$definition['date']}?->format('d/m/Y'), $module === 'cash_movements' ? $record->financialAccount?->name : ($record->description ?? '')]),
                    'can_pay' => str_starts_with($module, 'account_') && in_array($state?->value, $definition['pending'], true),
                    'can_delete' => $module === 'production_requests' && $state?->value === 'open',
                ];
            });
    }

    public function requestDelete(int $id): void
    {
        abort_unless($this->moduleKey() === 'production_requests', 403);
        $record = OperationResourceSupport::query($this->moduleKey())->findOrFail($id);
        abort_unless($record->status->value === 'open', 403);
        $this->deleteRecordId = $id;
        $this->showDeleteModal = true;
    }

    public function confirmDelete(): void
    {
        abort_unless($this->showDeleteModal && $this->deleteRecordId, 403);
        $this->deleteProductionRequest($this->deleteRecordId);
        $this->showDeleteModal = false;
    }

    public function deleteProductionRequest(int $id): void
    {
        abort_unless($this->moduleKey() === 'production_requests' && $this->showDeleteModal && $this->deleteRecordId === $id, 403);
        $record = OperationResourceSupport::query($this->moduleKey())->findOrFail($id);
        if ($record->status->value !== 'open') {
            $this->error('Somente pedidos abertos podem ser excluídos.');

            return;
        }
        $record->delete();
        $this->success('Pedido excluído com sucesso.');
        $this->resetPage();
    }
}
