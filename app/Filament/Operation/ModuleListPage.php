<?php

namespace App\Filament\Operation;

use App\Filament\Operation\Concerns\HasDateFilters;
use App\Filament\Operation\Concerns\HasModulePayment;
use App\Filament\Operation\Concerns\HasOperationFeedback;
use App\Filament\Operation\Concerns\HasOperationResourcePage;
use Carbon\Carbon;
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

    protected function baseQuery(?string $tab = null): Builder
    {
        $module = $this->moduleKey();
        $definition = OperationResourceSupport::definition($module);
        $query = OperationResourceSupport::query($module)->with($definition['relation']);
        if ($module === 'production_requests') {
            $query->with(['items', 'createdBy']);
        } elseif ($module === 'cash_movements') {
            $query->with('counterpartyPartner');
        }
        if (str_starts_with($module, 'account_')) {
            $query->withSum(['installments as open_balance' => fn (Builder $q) => $q->open()], 'balance_amount')
                ->withCount(['installments as ignored_count' => fn (Builder $q) => $q->whereNotNull('ignored_at')])
                ->withMin(['installments as next_due' => fn (Builder $q) => $q->open()], 'due_date');
            if ($this->dateFrom || $this->dateUntil) {
                $query->whereHas('installments', function (Builder $q) use ($tab): void {
                    ($tab ?? $this->activeTab) === 'open' ? $q->open() : $q->considered();
                    $this->applyDateRange($q, 'due_date');
                });
            }
        } else {
            $this->applyDateRange($query, $definition['date']);
        }
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
            'open' => [str_starts_with($this->moduleKey(), 'account_') ? 'Em aberto' : $definition['tabs'][0], $this->tabQuery('open')->count()],
            'closed' => [$definition['tabs'][1], $this->tabQuery('closed')->count()],
            'all' => ['Todos', $this->tabQuery('all')->count()],
        ];
    }

    protected function tabQuery(string $tab): Builder
    {
        $module = $this->moduleKey();
        $definition = OperationResourceSupport::definition($module);
        $field = $module === 'cash_movements' ? 'direction' : 'status';
        $query = $this->baseQuery($tab);

        return $query
            ->when($tab === 'open', fn (Builder $q) => str_starts_with($module, 'account_')
                ? $q->whereHas('installments', fn (Builder $q) => $q->open())
                : $q->whereIn($field, $definition['pending']))
            ->when($tab === 'closed', fn (Builder $q) => $q->where($field, $definition['settled']));
    }

    public function getRecordsProperty(): LengthAwarePaginator
    {
        $module = $this->moduleKey();
        $definition = OperationResourceSupport::definition($module);
        $field = $module === 'cash_movements' ? 'direction' : 'status';

        return $this->tabQuery($this->activeTab)
            ->orderBy($definition['date'], str_starts_with($module, 'account_') ? 'asc' : 'desc')->orderByDesc('id')->paginate(15)
            ->through(function ($record) use ($module, $definition, $field): array {
                $state = $record->{$field};
                $financial = str_starts_with($module, 'account_');
                $amount = $module === 'production_requests' ? $record->total_amount : ($module === 'cash_movements' ? $record->amount : (float) $record->open_balance / 100);
                $date = $financial ? ($record->next_due ? Carbon::parse($record->next_due)->format('d/m/Y') : null) : $record->{$definition['date']}?->format('d/m/Y');
                $ignored = $financial && $record->ignored_count > 0;

                return [
                    'id' => $record->id, 'url' => OperationResourceSupport::resource($module)::getUrl($financial ? 'view' : 'edit', ['record' => $record->id]),
                    'title' => $module === 'production_requests' ? 'Pedido #'.$record->number : ($record->document_number ?: ($record->description ?: 'Registro #'.$record->id)),
                    'subtitle' => $record->counterparty_label, 'status' => $ignored ? ($amount > 0 ? 'Parcialmente ignorada' : 'Ignorada') : ($state?->description() ?? '-'), 'color' => $ignored ? 'gray' : ($state && method_exists($state, 'color') ? $state->color() : ($state?->value === 'inflow' ? 'success' : 'danger')),
                    'amount' => ($financial ? 'Saldo em aberto: ' : '').OperationResourceSupport::money($amount), 'details' => array_filter([$date, $financial ? 'Total da conta: '.OperationResourceSupport::money($record->due_amount) : null, $financial ? 'Saldo de todas as parcelas consideradas' : null, $module === 'cash_movements' ? $record->financialAccount?->name : ($record->description ?? '')]),
                    'can_pay' => $financial && $amount > 0,
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
