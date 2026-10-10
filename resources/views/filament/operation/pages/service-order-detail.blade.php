<x-operation.page :title="$order ? 'OS #'.$order['number'] : 'Detalhe da OS'" :subtitle="$order ? $order['type'].' · '.$order['priority'].' · '.$order['order_date'] : null">
    @if ($order)
        <div class="grid grid-cols-3 gap-3">
            <x-operation.stat title="Valor" :value="$order['total']" />
            <x-operation.stat title="Status" :value="$order['status_label']" />
            <x-operation.stat title="Local" :value="$order['location']" />
        </div>
        <x-operation.panel title="Cliente" :subtitle="$order['customer_doc']">
            <p class="font-semibold">{{ $order['customer_name'] }}</p>
        </x-operation.panel>
        <x-operation.panel :title="'Serviços ('.count($order['items']).')'">
            <div class="space-y-3">
                @forelse ($order['items'] as $item)
                    <div class="rounded-box bg-base-200 p-4" wire:key="service-order-item-{{ $item['id'] }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold">{{ $item['name'] }}</p>
                                <p class="mt-1 text-sm text-base-content/60">{{ $item['quantity'] }} × {{ $item['unit_price'] }} = {{ $item['total'] }}</p>
                            </div>
                            @if ($order['is_open'])
                                <x-mary-button icon="o-pencil-square" class="btn-ghost btn-sm btn-circle" :wire:click="'openEditService('.$item['id'].')'" aria-label="Editar serviço" tooltip="Editar serviço" />
                            @endif
                        </div>
                        @if ($item['observations'])
                            <p class="mt-2 text-sm text-base-content/60">{{ $item['observations'] }}</p>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">Nenhum serviço adicionado. Use o botão + para incluir.</p>
                @endforelse
            </div>
        </x-operation.panel>

        @if ($order['can_edit'])
            <x-mary-form wire:submit="save" no-separator class="space-y-6">
                <x-operation.panel title="Responsáveis e equipamento">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-operation.search-select label="Técnico" wire:model="formData.technician_id" :options="$this->technicians" placeholder="Buscar técnico" offline />
                        <x-operation.search-select label="Equipamento" wire:model="formData.equipment_id" :options="$this->equipments" placeholder="Buscar equipamento" offline />
                    </div>
                </x-operation.panel>
                <x-mary-collapse class="bg-base-100 rounded-box border-base-300" separator>
                    <x-slot:heading class="px-5 py-4 sm:px-6">Registro do Atendimento</x-slot:heading>
                    <x-slot:content class="px-5 pb-5 pt-2 sm:px-6">
                        <div class="space-y-4">
                            <x-mary-textarea label="Observações do Cliente" wire:model="formData.customer_observations" rows="3" />
                            <x-mary-textarea label="Itens recebidos" wire:model="formData.items_received" rows="3" />
                            <x-mary-textarea label="Observações gerais" wire:model="formData.general_observations" rows="3" />
                            <x-mary-textarea label="Solução Aplicada" wire:model="formData.solution" rows="3" />
                            <x-mary-textarea label="Observações do Técnico" wire:model="formData.technician_observations" rows="3" />
                        </div>
                    </x-slot:content>
                </x-mary-collapse>
            </x-mary-form>
        @else
            <x-operation.panel title="Responsáveis e equipamento">
                <p>{{ $order['technician_name'] }} · {{ $order['equipment_name'] }} {{ $order['equipment_identifier'] }}</p>
            </x-operation.panel>
            <x-mary-collapse class="bg-base-100 rounded-box" separator>
                <x-slot:heading>Registro do Atendimento</x-slot:heading>
                <x-slot:content>
                    <div class="space-y-4">
                        @foreach (['customer_observations' => 'Observações do Cliente', 'items_received' => 'Itens recebidos', 'general_observations' => 'Observações gerais', 'solution' => 'Solução Aplicada', 'technician_observations' => 'Observações do Técnico'] as $field => $label)
                            <div><p class="text-sm text-base-content/60">{{ $label }}</p><p class="whitespace-pre-wrap">{{ $order[$field] ?: '-' }}</p></div>
                        @endforeach
                    </div>
                </x-slot:content>
            </x-mary-collapse>
        @endif

        @if ($order['is_open'])
            <x-operation.fab label="Adicionar serviço" method="openAddService" />
        @endif
    @else
        <x-mary-alert title="Ordem de serviço não encontrada." icon="o-exclamation-circle" class="alert-warning" />
    @endif

    {{ $this->getFooter() }}

    <x-mary-modal wire:model="showServiceModal" :title="$editingItemId ? 'Editar serviço' : 'Adicionar serviço'" subtitle="Defina o serviço, a quantidade e os valores." box-class="max-w-2xl" class="backdrop-blur-sm">
        <x-mary-form wire:submit="saveService" no-separator>
            <x-operation.search-select label="Serviço" wire:model.live="serviceData.service_id" :options="$services" search-function="searchServices" placeholder="Buscar nome ou código" debounce="300ms" />
            <div class="grid grid-cols-2 gap-4">
                <x-mary-input label="Quantidade" wire:model.blur="serviceData.quantity" inputmode="decimal" />
                <x-mary-input label="Preço unitário" wire:model.blur="serviceData.unit_price" prefix="R$" inputmode="decimal" :hint="$this->minimumServicePrice" />
                <x-mary-input label="Desconto (%)" wire:model.blur="serviceData.discount_percentage" suffix="%" inputmode="decimal" />
                <x-mary-input label="Desconto (R$)" wire:model.blur="serviceData.discount_amount" prefix="R$" inputmode="decimal" />
            </div>
            <x-operation.stat title="Total do serviço" :value="$this->serviceTotal" icon="o-banknotes" class="bg-base-200" />
            <x-mary-textarea label="Observações" wire:model="serviceData.observations" placeholder="Detalhes específicos deste serviço" rows="2" />
            <x-slot:actions>
                <x-mary-button label="Voltar" @click="$wire.showServiceModal = false" />
                <x-mary-button :label="$editingItemId ? 'Salvar serviço' : 'Adicionar serviço'" class="btn-primary" type="submit" spinner="saveService" />
            </x-slot:actions>
        </x-mary-form>
    </x-mary-modal>
</x-operation.page>
