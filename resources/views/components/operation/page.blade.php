@props(['title', 'subtitle' => null])

<x-operation.theme class="space-y-5 pb-4">
    <x-mary-header :title="$title" :subtitle="$subtitle" size="text-2xl" class="!mb-0" />
    {{ $slot }}
</x-operation.theme>
