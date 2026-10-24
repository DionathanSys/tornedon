@props(['paginator', 'label' => 'Paginação'])

@php
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();
    $pageName = $paginator->getPageName();
    $pages = array_values(array_unique([1, max(1, $current - 1), $current, min($last, $current + 1), $last]));
    sort($pages);
    $previous = 0;
@endphp

<nav class="financial-dashboard__pagination" aria-label="{{ $label }}" wire:key="pagination-{{ $pageName }}">
    <p class="financial-dashboard__muted">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }} registros</p>
    <div class="financial-dashboard__pagination-buttons">
        <x-filament::button color="gray" size="sm" icon="heroicon-m-chevron-left"
            :disabled="$paginator->onFirstPage()"
            wire:click="previousPage('{{ $pageName }}')"
            aria-label="Página anterior"
        >Anterior</x-filament::button>

        @foreach($pages as $page)
            @if($page > $previous + 1)
                <span class="financial-dashboard__muted" aria-hidden="true">…</span>
            @endif
            <x-filament::button :color="$page === $current ? 'primary' : 'gray'" size="sm"
                wire:click="gotoPage({{ $page }}, '{{ $pageName }}')"
                wire:key="{{ $pageName }}-page-{{ $page }}"
                :aria-current="$page === $current ? 'page' : null"
                :aria-label="'Ir para página ' . $page"
            >{{ $page }}</x-filament::button>
            @php
                $previous = $page;
            @endphp
        @endforeach

        <x-filament::button color="gray" size="sm" icon="heroicon-m-chevron-right" icon-position="after"
            :disabled="! $paginator->hasMorePages()"
            wire:click="nextPage('{{ $pageName }}')"
            aria-label="Próxima página"
        >Próxima</x-filament::button>
    </div>
</nav>
