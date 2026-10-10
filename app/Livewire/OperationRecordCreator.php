<?php

namespace App\Livewire;

use App\Enum\Requisition\Status;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State;
use App\Enum\ServiceOrder\Type;
use App\Filament\Operation\Pages\Requisitions\RequisitionDetail;
use App\Filament\Operation\Pages\ServiceOrders\ServiceOrderDetail;
use App\Models\Partner;
use App\Services\Partner\PartnerService;
use App\Services\Partner\QuickCreateCustomerPartnerService;
use App\Services\Requisition\RequisitionService;
use App\Services\ServiceOrder\ServiceOrderService;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Mary\Traits\Toast;

class OperationRecordCreator extends Component
{
    use Toast;

    public bool $showModal = false;

    #[Locked]
    public string $kind = 'service-order';

    public int|string|null $customerId = null;

    public array $customers = [];

    public bool $showNewCustomer = false;

    public array $newCustomer = [];

    #[On('operation-create-record')]
    public function open(string $kind): void
    {
        abort_unless(Filament::getTenant() && in_array($kind, ['service-order', 'requisition'], true), 403);
        $this->resetValidation();
        $this->kind = $kind;
        $this->customerId = null;
        $this->showNewCustomer = false;
        $this->newCustomer = ['name' => '', 'document_type' => 'cnpj', 'document_number' => '', 'state_tax_indicator' => '9', 'state_tax_id' => ''];
        $this->searchCustomers();
        $this->showModal = true;
    }

    public function searchCustomers(string $search = ''): void
    {
        $companyId = Filament::getTenant()?->getKey();
        abort_unless($companyId, 403);
        $options = app(PartnerService::class)->searchForSelect($search, $companyId, 'customer');

        if ($this->customerId && $this->customerQuery()->whereKey($this->customerId)->exists()) {
            $options[$this->customerId] = app(PartnerService::class)->getLabelForSelect((int) $this->customerId);
        }

        $this->customers = collect($options)->map(fn ($name, $id): array => ['id' => (int) $id, 'name' => $name])->values()->all();
    }

    public function createCustomer(): void
    {
        abort_unless(Filament::getTenant(), 403);
        $data = $this->validate([
            'newCustomer.name' => 'required|string|max:60',
            'newCustomer.document_type' => ['required', Rule::in(['cpf', 'cnpj'])],
            'newCustomer.document_number' => 'required|string|max:20',
            'newCustomer.state_tax_indicator' => ['required', Rule::in(['1', '2', '9'])],
            'newCustomer.state_tax_id' => 'nullable|string|max:30',
        ])['newCustomer'];
        $digits = preg_replace('/\D/', '', $data['document_number']);
        $data['document_number'] = $data['document_type'] === 'cpf'
            ? preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $digits)
            : preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digits);
        $service = app(QuickCreateCustomerPartnerService::class);
        $record = $service->create((int) Auth::id(), Filament::getTenant()->getKey(), [...$data, 'invoice_threshold' => 0]);

        if (! $record) {
            $this->addError('newCustomer.document_number', $service->getMessageUser());

            return;
        }

        $this->customerId = $record->partner_id;
        $this->showNewCustomer = false;
        $this->searchCustomers();
        $this->success('Cliente cadastrado.');
    }

    public function create(): void
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant, 403);
        $this->validate(['customerId' => ['required', 'integer']]);

        if (! $this->customerQuery()->whereKey($this->customerId)->exists()) {
            $this->addError('customerId', 'Selecione um cliente ativo desta empresa.');

            return;
        }

        $data = ['customer_id' => $this->customerId, 'company_id' => $tenant->getKey()];

        if ($this->kind === 'service-order') {
            $service = app(ServiceOrderService::class);
            $record = $service->create([...$data, 'order_date' => now(), 'status' => State::OPEN, 'priority' => Priority::NORMAL, 'type' => Type::MAINTENANCE], (int) Auth::id());
            $page = ServiceOrderDetail::class;
        } else {
            $service = app(RequisitionService::class);
            $record = $service->create([...$data, 'sale_date' => now(), 'delivery_date' => now(), 'status' => Status::OPEN], (int) Auth::id());
            $page = RequisitionDetail::class;
        }

        if ($service->hasError() || ! $record) {
            $this->addError('customerId', $service->getMessageUser());

            return;
        }

        $this->showModal = false;
        $this->redirect($page::getUrl(['record' => $record], tenant: $tenant), navigate: true);
    }

    public function updatedNewCustomer(mixed $value, ?string $key = null): void
    {
        if ($key === 'document_type') {
            $this->newCustomer['document_number'] = '';
        }
    }

    private function customerQuery(): Builder
    {
        return Partner::query()->whereHas('companies', fn ($query) => $query
            ->where('companies.id', Filament::getTenant()?->getKey())
            ->where('company_partner.is_active', true)
            ->whereJsonContains('company_partner.type', 'customer'));
    }

    public function render(): View
    {
        return view('livewire.operation-record-creator');
    }
}
