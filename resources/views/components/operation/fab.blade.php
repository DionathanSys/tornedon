@props(['label', 'method' => null, 'kind' => null, 'actions' => [], 'event' => 'operation-create-record'])
@php
    $key = 'operation.fab.'.filament()->auth()->id().'.'.filament()->getTenant()?->getKey().'.'.($kind ?: $method);
@endphp

<div
    x-data="operationFab({ key: @js($key), kind: @js($kind), method: @js($method), event: @js($event) })"
    @pointermove.window="pointerMove($event)"
    @pointerup.window="finishPointer($event)"
    @pointercancel.window="finishPointer($event, true)"
    @keydown.escape.window="if (menuOpen) closeMenu()"
>
    <x-mary-button
        x-ref="button"
        x-show.important="!hidden"
        x-cloak
        :aria-label="$label"
        :tooltip="$label.' · Arraste para mover ou segure para opções'"
        icon="o-plus"
        class="btn-primary btn-circle operation-fab"
        x-bind:style="positionStyle"
        x-bind:data-dragging="dragging"
        x-bind:aria-expanded="menuOpen"
        aria-haspopup="menu"
        @pointerdown="pointerDown($event)"
        @click="activate($event)"
        @contextmenu.prevent="openOptions()"
        @keydown.shift.f10.prevent="openOptions()"
    />

    <x-mary-button x-show.important="hidden" x-cloak aria-label="Mostrar botão flutuante" :tooltip="'Mostrar '.$label" icon="o-eye" class="btn-ghost btn-circle operation-fab-restore" @click="showFab()" />

    <div x-ref="options" x-show="menuOpen" x-cloak x-trap="menuOpen" @click.outside="closeMenu()" x-bind:style="menuStyle" class="operation-fab-menu" role="menu" aria-label="Opções do botão flutuante">
        <x-mary-menu class="p-2">
            <x-mary-menu-item :title="$label" icon="o-plus" @click="activatePrimary()" role="menuitem" tabindex="0" @keydown.enter.prevent="$el.click()" @keydown.space.prevent="$el.click()" />
            @foreach ($actions as $action)
                @if (isset($action['url']))
                    <x-mary-menu-item :id="'fab-action-'.$key.'-'.$loop->index" :title="$action['label']" :icon="$action['icon']" :link="$action['url']" @click="closeMenu()" role="menuitem" />
                @else
                    <x-mary-menu-item :id="'fab-action-'.$key.'-'.$loop->index" :title="$action['label']" :icon="$action['icon']" :wire:click="($action['confirm'] ?? false) ? 'requestOperationConfirmation(\''.$action['method'].'\')' : $action['method']" @click="closeMenu()" role="menuitem" tabindex="0" @keydown.enter.prevent="$el.click()" @keydown.space.prevent="$el.click()" />
                @endif
            @endforeach
            <x-mary-menu-separator />
            <x-mary-menu-item title="Restaurar posição" icon="o-arrow-path" @click="resetPosition()" role="menuitem" tabindex="0" @keydown.enter.prevent="$el.click()" @keydown.space.prevent="$el.click()" />
            <x-mary-menu-item title="Ocultar botão" icon="o-eye-slash" @click="hideFab()" role="menuitem" tabindex="0" @keydown.enter.prevent="$el.click()" @keydown.space.prevent="$el.click()" />
        </x-mary-menu>
    </div>
</div>
