@props(['record', 'title', 'subtitle', 'status', 'color' => 'gray', 'details' => [], 'amount' => null, 'icon' => 'o-clipboard-document-list'])

<x-mary-list-item :item="$record" :link="$record['url']" class="!items-start !gap-3 !px-4 !py-4 sm:!gap-4 sm:!px-5 sm:!py-5">
    <x-slot:avatar>
        <div class="flex h-10 w-10 items-center justify-center rounded-box bg-base-200 text-base-content/60">
            <x-mary-icon :name="$icon" class="h-5 w-5" />
        </div>
    </x-slot:avatar>
    <x-slot:value class="!whitespace-normal break-words text-sm sm:text-base">{{ $title }}</x-slot:value>
    <x-slot:sub-value class="!whitespace-normal">
        <p class="mt-1 break-words font-medium text-base-content/80">{{ $subtitle }}</p>
        <div class="mt-2 space-y-1 text-xs text-base-content/60 sm:text-sm">
            @foreach ($details as $detail)
                <p class="break-words">{{ $detail }}</p>
            @endforeach
        </div>
    </x-slot:sub-value>
    <x-slot:actions class="!flex-col !items-end !gap-3">
        <x-operation.status :value="$status" :color="$color" />
        @if ($amount)
            <span class="whitespace-nowrap text-sm font-semibold">{{ $amount }}</span>
        @endif
    </x-slot:actions>
</x-mary-list-item>
