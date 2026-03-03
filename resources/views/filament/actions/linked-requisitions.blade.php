<ul class="divide-y divide-gray-100 dark:divide-white/5">
    @foreach ($requisitions as $req)
        <li class="py-2 px-1">
            <x-filament::link
                :href="$req->url"
                icon="heroicon-m-clipboard-document-list"
                target="_blank"
            >
                Requisição #{{ $req->number }}
            </x-filament::link>
        </li>
    @endforeach
</ul>
