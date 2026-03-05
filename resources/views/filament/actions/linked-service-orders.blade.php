<ul class="divide-y divide-gray-100 dark:divide-white/5">
    @foreach ($serviceOrders as $so)
        <li class="py-2 px-1">
            <x-filament::link
                :href="$so->url"
                icon="heroicon-m-wrench-screwdriver"
                target="_blank"
            >
                OS #{{ $so->number }}
            </x-filament::link>
        </li>
    @endforeach
</ul>
