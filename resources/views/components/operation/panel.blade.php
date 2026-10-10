@props(['title' => null, 'subtitle' => null])

<x-mary-card :title="$title" :subtitle="$subtitle" body-class="space-y-4" {{ $attributes->class(['border border-base-300 shadow-sm sm:p-6']) }}>
    {{ $slot }}
</x-mary-card>
