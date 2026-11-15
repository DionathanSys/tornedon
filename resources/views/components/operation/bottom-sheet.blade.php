@props(['title' => null, 'subtitle' => null])

<x-mary-modal :title="$title" :subtitle="$subtitle" {{ $attributes->class(['operation-bottom-sheet backdrop-blur-sm']) }}>
    {{ $slot }}
</x-mary-modal>
