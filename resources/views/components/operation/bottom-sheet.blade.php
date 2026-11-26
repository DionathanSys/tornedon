@props(['title' => null, 'subtitle' => null])

<div x-data="{ open: @entangle($attributes->wire('model')).live }" x-id="['operation-sheet-title', 'operation-sheet-description']">
    <template x-teleport="body">
        <x-operation.theme>
            <div
                {{ $attributes->except(['wire:model', 'wire:model.live'])->class(['operation-bottom-sheet']) }}
                x-show="open"
                x-cloak
                @keydown.escape.window="if (open) { $event.preventDefault(); $event.stopPropagation(); open = false }"
            >
                <div
                    class="operation-bottom-sheet-backdrop"
                    x-show="open"
                    x-transition.opacity.duration.250ms
                    @click="open = false"
                    aria-hidden="true"
                ></div>
                <section
                    class="operation-bottom-sheet-panel"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="$id('operation-sheet-title')"
                    @if ($subtitle) :aria-describedby="$id('operation-sheet-description')" @endif
                    x-show="open"
                    x-trap.inert.noscroll="open"
                    x-transition:enter="operation-bottom-sheet-transition"
                    x-transition:enter-start="operation-bottom-sheet-closed"
                    x-transition:enter-end="operation-bottom-sheet-open"
                    x-transition:leave="operation-bottom-sheet-transition"
                    x-transition:leave-start="operation-bottom-sheet-open"
                    x-transition:leave-end="operation-bottom-sheet-closed"
                >
                    <header class="operation-bottom-sheet-header">
                        <div class="operation-bottom-sheet-handle" aria-hidden="true"></div>
                        <div class="pe-10">
                            <h2 :id="$id('operation-sheet-title')" class="text-xl font-semibold">{{ $title }}</h2>
                            @if ($subtitle)
                                <p :id="$id('operation-sheet-description')" class="mt-1 text-sm text-base-content/60">{{ $subtitle }}</p>
                            @endif
                        </div>
                        <x-mary-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle absolute end-4 top-5" aria-label="Fechar janela" @click="open = false" />
                    </header>
                    <div class="operation-bottom-sheet-content">
                        {{ $slot }}
                    </div>
                </section>
            </div>
        </x-operation.theme>
    </template>
</div>
