@props(['paginator'])

<div class="mb-12 flex flex-col items-center justify-between gap-3 rounded-box border border-base-300 bg-base-100 p-4 sm:flex-row" aria-label="Paginação dos registros">
    <p class="text-xs text-base-content/60" aria-live="polite">
        Mostrando {{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }} de {{ $paginator->total() }} registros
    </p>
    @if ($paginator->hasPages())
        <div class="flex items-center gap-3">
            <x-mary-button label="Anterior" icon="o-chevron-left" class="btn-ghost btn-sm" wire:click="previousPage" spinner="previousPage" :disabled="$paginator->onFirstPage()" />
            <span class="whitespace-nowrap text-xs">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
            <x-mary-button label="Próxima" icon-right="o-chevron-right" class="btn-ghost btn-sm" wire:click="nextPage" spinner="nextPage" :disabled="! $paginator->hasMorePages()" />
        </div>
    @endif
</div>
