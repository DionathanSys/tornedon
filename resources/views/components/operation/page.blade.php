@props(['title', 'subtitle' => null])

<div class="grid gap-6 pb-4 sm:gap-8">
    <x-mary-header :title="$title" :subtitle="$subtitle" size="text-2xl" class="!mb-0" />
    {{ $slot }}
</div>
