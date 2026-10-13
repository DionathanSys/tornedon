@props(['title' => null, 'value' => null, 'icon' => null, 'color' => '', 'description' => null])

<x-mary-stat :title="$title" :value="$value" :icon="$icon" :color="$color" :description="$description" {{ $attributes->class(['operation-stat']) }}>
    {{ $slot }}
</x-mary-stat>
