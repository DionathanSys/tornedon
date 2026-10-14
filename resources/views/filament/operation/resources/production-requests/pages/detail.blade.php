<x-operation.page :title="'Pedido #'.$record->number" :subtitle="$record->order_date?->format('d/m/Y')">
    <div class="grid grid-cols-2 gap-3">
        <x-operation.stat title="Valor" :value="\App\Filament\Operation\OperationResourceSupport::money($record->total_amount)" />
        <x-operation.stat title="Status" :value="$record->status->description()" />
    </div>
    <x-operation.panel title="Cliente">
        <p class="font-semibold">{{ $record->counterparty_label }}</p>
    </x-operation.panel>
    <x-operation.panel :title="'Itens ('.$record->items->count().')'">
        <div class="space-y-3">
            @forelse ($record->items as $item)
                <div class="rounded-box bg-base-200 p-4" wire:key="production-item-{{ $item->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ $item->description }}</p>
                            <p class="mt-1 text-sm text-base-content/60">{{ number_format((float) $item->quantity, 3, ',', '.') }} {{ $item->unit_of_measure }} × {{ \App\Filament\Operation\OperationResourceSupport::money($item->unit_price) }}</p>
                        </div>
                        @if ($record->status === \App\Enum\ProductionRequest\Status::OPEN)
                            <x-mary-dropdown :id="'item-actions-'.$item->id" right>
                                <x-slot:trigger class="btn btn-ghost btn-sm btn-circle" aria-label="Ações do item">
                                    <x-mary-icon name="o-ellipsis-vertical" class="h-4 w-4" />
                                </x-slot:trigger>
                                <x-mary-menu-item title="Editar item" icon="o-pencil-square" :wire:click="'editItem('.$item->id.')'" />
                                <x-mary-menu-item title="Excluir item" icon="o-trash" :wire:click="'requestItemDelete('.$item->id.')'" />
                            </x-mary-dropdown>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-base-content/60">Nenhum item adicionado. Use o botão + para incluir.</p>
            @endforelse
        </div>
    </x-operation.panel>
    @if ($record->status === \App\Enum\ProductionRequest\Status::OPEN)
        <x-operation.panel title="Financeiro">
            <x-mary-form wire:submit="save" no-separator>
                @include('filament.operation.resources.form-fields')
            </x-mary-form>
        </x-operation.panel>
        <x-operation.fab label="Adicionar item" method="toggleItemForm" :actions="$this->getFooter()->getData()['actions']" />
    @endif
    {{ $this->getFooter() }}
    <x-mary-modal wire:model="showItemForm" :title="$editingItemId ? 'Editar item' : 'Adicionar item'" box-class="max-w-2xl" class="backdrop-blur-sm">
        <x-mary-form wire:submit="saveItem" no-separator>
            <x-operation.search-select label="Produto" wire:model.live="itemData.product_id" :options="collect($this->productOptions)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values()->all()" offline />
            <div class="grid grid-cols-2 gap-4">
                <x-mary-input label="Unidade" wire:model="itemData.unit_of_measure" />
                <x-mary-input label="Quantidade" wire:model.blur="itemData.quantity" inputmode="decimal" />
                <x-mary-input label="Preço unitário" wire:model.blur="itemData.unit_price" prefix="R$" inputmode="decimal" />
            </div>
            <x-slot:actions>
                <x-mary-button label="Voltar" @click="$wire.showItemForm = false" />
                <x-mary-button label="Salvar e adicionar outro" wire:click="saveItem(true)" spinner="saveItem" />
                <x-mary-button :label="$editingItemId ? 'Salvar item' : 'Adicionar item'" class="btn-primary" type="submit" spinner="saveItem" />
            </x-slot:actions>
        </x-mary-form>
    </x-mary-modal>
    <x-mary-modal wire:model="showDeliverConfirmation" title="Entregar pedido" subtitle="Confirme a entrega e, se necessário, registre o recebimento." class="backdrop-blur-sm">
        <x-mary-form wire:submit="confirmDeliver" no-separator>
            <x-mary-checkbox label="Registrar recebimento agora" wire:model.live="deliverData.mark_as_received" />
            @if ($deliverData['mark_as_received'] ?? false)
                <x-operation.search-select label="Conta financeira" wire:model="deliverData.financial_account_id" :options="$this->formOptions('accounts')" offline />
                <x-mary-input label="Data do recebimento" type="date" wire:model="deliverData.received_at" />
            @endif
            <x-slot:actions>
                <x-mary-button label="Voltar" @click="$wire.showDeliverConfirmation = false" />
                <x-mary-button label="Confirmar entrega" class="btn-primary" type="submit" spinner="confirmDeliver" />
            </x-slot:actions>
        </x-mary-form>
    </x-mary-modal>
    <x-mary-modal wire:model="showDeleteItemModal" title="Excluir item?" subtitle="Confirme para continuar." class="backdrop-blur-sm">
        <x-slot:actions>
            <x-mary-button label="Voltar" @click="$wire.showDeleteItemModal = false" />
            <x-mary-button label="Excluir" class="btn-error" wire:click="confirmItemDelete" spinner="confirmItemDelete" />
        </x-slot:actions>
    </x-mary-modal>
</x-operation.page>
