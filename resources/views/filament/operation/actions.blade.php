@php
    $visible = collect($actions)->take(3);
    $more = collect($actions)->slice(3);
@endphp

<x-operation.bottom-bar :columns="$visible->count() + ($more->isNotEmpty() ? 1 : 0)" label="Ações do registro">
    @foreach ($visible as $action)
        @if (isset($action['url']))
            <x-mary-button :label="$action['label']" :icon="$action['icon']" :link="$action['url']" class="btn-ghost operation-bar-button" />
        @else
            <x-mary-button :label="$action['label']" :icon="$action['icon']" :wire:click="($action['confirm'] ?? false) ? 'requestOperationConfirmation(\''.$action['method'].'\')' : $action['method']" :class="'operation-bar-button '.($action['primary'] ?? false ? 'btn-primary' : 'btn-ghost')" :spinner="$action['method']" />
        @endif
    @endforeach
    @if ($more->isNotEmpty())
        <x-mary-dropdown no-x-anchor top right>
            <x-slot:trigger class="btn btn-ghost operation-bar-button">
                <x-mary-icon name="o-ellipsis-horizontal" class="h-5 w-5" />
                <span>Mais</span>
            </x-slot:trigger>
            @foreach ($more as $action)
                <x-mary-menu-item :title="$action['label']" :icon="$action['icon']" :wire:click="($action['confirm'] ?? false) ? 'requestOperationConfirmation(\''.$action['method'].'\')' : $action['method']" />
            @endforeach
        </x-mary-dropdown>
    @endif
</x-operation.bottom-bar>

<x-mary-modal wire:model="showConfirmation" :title="$this->confirmationOperation === 'cancel' ? 'Cancelar registro?' : 'Encerrar registro?'" subtitle="Confirme para continuar." class="backdrop-blur-sm">
    <x-slot:actions>
        <x-mary-button label="Voltar" @click="$wire.showConfirmation = false" />
        <x-mary-button label="Confirmar" :class="$this->confirmationOperation === 'cancel' ? 'btn-error' : 'btn-primary'" wire:click="confirmOperation" spinner="confirmOperation" />
    </x-slot:actions>
</x-mary-modal>
