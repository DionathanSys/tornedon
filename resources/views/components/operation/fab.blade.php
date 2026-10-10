@props(['label', 'method' => null, 'kind' => null])

@if ($kind)
    <x-mary-button :aria-label="$label" :tooltip="$label" :data-record-kind="$kind" icon="o-plus" class="btn-primary btn-circle operation-fab" @click="$dispatch('operation-create-record', { kind: $el.dataset.recordKind })" />
@else
    <x-mary-button :aria-label="$label" :tooltip="$label" icon="o-plus" class="btn-primary btn-circle operation-fab" :wire:click="$method" :spinner="$method" />
@endif
