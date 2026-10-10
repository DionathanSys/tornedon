@props(['title' => null, 'value' => null, 'icon' => null, 'color' => ''])

<x-mary-stat :title="$title" :value="$value" :icon="$icon" :color="$color" {{ $attributes->class(['operation-stat']) }}>
    {{ $slot }}
</x-mary-stat>
