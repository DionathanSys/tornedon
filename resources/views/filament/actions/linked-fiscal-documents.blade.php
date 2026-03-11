<ul class="divide-y divide-gray-100 dark:divide-white/5">
    @foreach ($fiscalDocuments as $doc)
        <li class="py-2 px-1">
            <x-filament::link
                :href="$doc->url"
                icon="heroicon-m-document-text"
                target="_blank"
            >
                NF #{{ $doc->number }}
            </x-filament::link>
        </li>
    @endforeach
</ul>
