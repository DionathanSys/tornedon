@props(['title'])

<x-operation.page :title="$title">
    <div class="operation-resource">
        {{ $slot }}
    </div>
    <x-filament-actions::modals />
</x-operation.page>
