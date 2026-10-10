@props(['label' => null, 'options' => [], 'offline' => false])

<x-dynamic-component
    :component="$offline ? 'mary-choices-offline' : 'mary-choices'"
    :label="$label"
    :options="$options"
    single
    searchable
    clearable
    escape-values
    @change-selection="$nextTick(() => resize())"
    no-result-text="Nenhum resultado encontrado"
    {{ $attributes->class(['operation-single-select']) }}
/>
