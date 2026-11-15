<x-mary-collapse id="service-order-defects" class="bg-base-100 rounded-box border-base-300" separator>
    <x-slot:heading class="px-5 py-4 sm:px-6">Defeitos relatados ({{ count($order['defects']) }})</x-slot:heading>
    <x-slot:content class="px-5 pb-5 pt-2 sm:px-6">
        <div class="space-y-3">
            @forelse($order['defects'] as $defect)
                <div class="flex items-start justify-between gap-3 rounded-box bg-base-200 p-4" wire:key="service-order-defect-{{ $defect['id'] }}">
                    <p class="min-w-0 whitespace-pre-wrap break-words"><span class="font-semibold">{{ $loop->iteration }}.</span> {{ $defect['report'] }}</p>
                    @if($order['can_edit'])
                        <div class="flex shrink-0">
                            <x-mary-button icon="o-pencil-square" class="btn-ghost btn-sm btn-circle" :wire:click="'openEditDefect('.$defect['id'].')'" aria-label="Editar defeito" tooltip="Editar defeito" />
                            <x-mary-button icon="o-trash" class="btn-ghost btn-sm btn-circle text-error" :wire:click="'removeDefect('.$defect['id'].')'" wire:confirm="Remover este defeito da ficha?" aria-label="Remover defeito" tooltip="Remover defeito" />
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-sm text-base-content/60">Nenhum defeito registrado na lista.</p>
            @endforelse
            @if($order['can_edit'] && ! $order['is_open'])
                <x-mary-button label="Adicionar defeito" icon="o-plus" wire:click="addDefect" class="btn-outline btn-sm" />
            @endif
        </div>
    </x-slot:content>
</x-mary-collapse>
