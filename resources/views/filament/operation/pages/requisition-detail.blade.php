<x-operation.page :title="$requisition ? $requisition['number'] : 'Detalhe da requisição'">
    @if ($requisition)
        <div class="grid grid-cols-2 gap-3">
            <x-operation.stat title="Total" :value="$requisition['total']" />
            <x-operation.stat title="Status" :value="$requisition['status']" />
        </div>
        <x-operation.panel title="Cliente e equipamento">
            <p class="font-semibold">{{ $requisition['customer_name'] }}</p>
            <p class="text-sm text-base-content/60">{{ $requisition['customer_doc'] }}</p>
            <p class="mt-3">{{ $requisition['equipment_name'] }} {{ $requisition['equipment_identifier'] }}</p>
            <p class="mt-1 text-sm text-base-content/60">OS: {{ $requisition['service_order_number'] }} · {{ $requisition['sale_date'] }}</p>
            @if ($requisition['service_order_url'])
                <x-mary-button label="Abrir ordem de serviço" icon="o-wrench-screwdriver" :link="$requisition['service_order_url']" class="btn-ghost btn-sm" />
            @endif
            @if ($requisition['invoice_number'])
                <p class="text-sm text-base-content/60">Fatura: {{ $requisition['invoice_number'] }}</p>
            @endif
        </x-operation.panel>
        <x-mary-collapse id="requisition-products" class="bg-base-100 rounded-box border-base-300" separator>
            <x-slot:heading class="px-5 py-4 sm:px-6">Produtos ({{ count($requisition['items']) }})</x-slot:heading>
            <x-slot:content class="px-5 pb-5 pt-2 sm:px-6">
                <div class="space-y-3">
                    @forelse ($requisition['items'] as $item)
                        <div class="rounded-box bg-base-200 p-4" wire:key="requisition-item-{{ $item['id'] }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="break-words font-semibold">{{ $item['name'] }}</p>
                                    <x-operation.status :value="$item['stock_consumed'] ? 'Estoque baixado' : 'Pendente'" :color="$item['stock_consumed'] ? 'success' : 'warning'" />
                                </div>
                                @if ($requisition['can_edit'])
                                    <div class="flex shrink-0">
                                        <x-mary-button icon="o-pencil-square" class="btn-ghost btn-sm btn-circle" :wire:click="'openEditProduct('.$item['id'].')'" aria-label="Editar produto" tooltip="Editar produto" />
                                        <x-mary-button icon="o-trash" class="btn-ghost btn-sm btn-circle text-error" :wire:click="'deleteProduct('.$item['id'].')'" wire:confirm="Excluir este produto da requisição?" aria-label="Excluir produto" tooltip="Excluir produto" />
                                    </div>
                                @endif
                            </div>
                            <p class="mt-2 text-sm text-base-content/60">{{ $item['quantity'] }} {{ $item['unit'] }} × {{ $item['unit_price'] }} = {{ $item['total'] }}</p>
                            <p class="mt-1 text-sm text-base-content/60">Desconto: {{ $item['discount'] }}</p>
                            <p class="mt-1 text-xs text-base-content/60">Código: {{ $item['code'] }}</p>
                            @if ($item['observations'])
                                <p class="mt-2 whitespace-pre-wrap text-sm text-base-content/60">{{ $item['observations'] }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-base-content/60">Nenhum item adicionado.</p>
                    @endforelse
                </div>
            </x-slot:content>
        </x-mary-collapse>
        @if ($requisition['can_edit'])
            <x-mary-form wire:submit="save" no-separator>
                <x-operation.panel title="Equipamento e vendedor">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-operation.search-select label="Equipamento" wire:model="formData.equipment_id" :options="$this->equipments" placeholder="Selecionar equipamento" offline />
                        <x-operation.search-select label="Vendedor" wire:model="formData.salesperson_id" :options="$this->salespeople" placeholder="Selecionar vendedor" offline />
                    </div>
                </x-operation.panel>
                <x-mary-collapse id="requisition-payment" class="bg-base-100 rounded-box border-base-300" separator>
                    <x-slot:heading class="px-5 py-4 sm:px-6">Pagamento e entrega</x-slot:heading>
                    <x-slot:content class="px-5 pb-5 pt-2 sm:px-6">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <x-mary-select label="Forma de pagamento" wire:model="formData.payment_method" :options="collect(\App\Enum\Payment\Method::cases())->map(fn ($method) => ['id' => $method->value, 'name' => $method->description()])->all()" placeholder="Não definida" />
                            <x-mary-select label="Condição de pagamento" wire:model.live="formData.payment_condition" :options="collect(\App\Enum\Payment\Condition::cases())->map(fn ($condition) => ['id' => $condition->value, 'name' => $condition->description()])->all()" placeholder="Não definida" />
                            @if (($formData['payment_condition'] ?? null) === 'personalizado')
                                <x-mary-input label="Quantidade de parcelas" type="number" min="1" max="999" wire:model="formData.payment_condition_details.installments" />
                                <x-mary-input label="Primeiro vencimento em (dias)" type="number" min="0" wire:model="formData.payment_condition_details.first_due_days" />
                                <x-mary-input label="Intervalo entre parcelas (dias)" type="number" min="1" max="365" wire:model="formData.payment_condition_details.interval_days" />
                            @endif
                            <x-mary-input label="Data de entrega" type="date" wire:model="formData.delivery_date" />
                            <x-mary-input label="Endereço de entrega" wire:model="formData.delivery_address" maxlength="500" />
                        </div>
                    </x-slot:content>
                </x-mary-collapse>
                <x-operation.panel title="Observações">
                    <x-mary-textarea label="Observações da requisição" wire:model="formData.observations" rows="3" maxlength="1000" />
                </x-operation.panel>
            </x-mary-form>
            <x-operation.fab label="Adicionar produto" method="openAddProduct" :actions="$this->getFooter()->getData()['actions']" />
        @else
            <x-operation.panel title="Pagamento e entrega">
                <p>Pagamento: {{ $requisition['payment_method'] }} · {{ $requisition['payment_condition'] }}</p>
                <p>Entrega: {{ $requisition['delivery_date'] }} · {{ $requisition['delivery_address'] ?: '-' }}</p>
                <p>Vendedor: {{ $requisition['salesperson_name'] }}</p>
            </x-operation.panel>
            @if ($requisition['observations'])
                <x-operation.panel title="Observações"><p class="whitespace-pre-wrap">{{ $requisition['observations'] }}</p></x-operation.panel>
            @endif
        @endif
    @else
        <x-mary-alert title="Requisição não encontrada." icon="o-exclamation-circle" class="alert-warning" />
    @endif
    {{ $this->getFooter() }}
    @include('filament.operation.product-modal')
</x-operation.page>
