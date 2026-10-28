<x-filament-panels::page class="bpo-workbench-page">
    <section class="bpo-workbench bpo-list-workbench" aria-label="{{ $this->getTitle() }}">
        <header class="bpo-workbench-header">
            <div><h2>{{ $this->getTitle() }}</h2><p>Pesquise e acompanhe os registros da empresa em operação.</p></div>
            <span class="bpo-badge">{{ filament()->getTenant()->name }}</span>
        </header>
        {{ $this->content }}
    </section>
</x-filament-panels::page>
