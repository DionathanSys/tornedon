<ul class="divide-y divide-gray-100 dark:divide-white/5">
    @foreach ($productionOrders as $po)
        <li class="py-2 px-1">
            <x-filament::link
                :href="$po->url"
                icon="heroicon-m-cog-6-tooth"
                target="_blank"
            >
                OP #{{ $po->number }}
            </x-filament::link>
        </li>
    @endforeach
</ul>
