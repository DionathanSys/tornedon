@php
    $availableActions = collect($actions)->filter(fn ($action) => $action->isVisible())->values();
    $visibleActions = $availableActions->take(3);
    $moreActions = $availableActions->slice(3);
    $columns = $visibleActions->count() + ($moreActions->isNotEmpty() ? 1 : 0);
@endphp

<nav
    class="op-bottom-nav op-record-actions op-record-actions--{{ $columns }}"
    aria-label="Ações do registro"
    x-data="{
        observer: null,
        updateSpacing() {
            document.documentElement.style.setProperty('--op-bottom-space', `${window.innerHeight - this.$el.getBoundingClientRect().top}px`);
        },
        init() {
            this.observer = new ResizeObserver(() => this.updateSpacing());
            this.observer.observe(this.$el);
            this.$nextTick(() => this.updateSpacing());
        },
        destroy() {
            this.observer?.disconnect();
            document.documentElement.style.removeProperty('--op-bottom-space');
        },
    }"
    x-on:resize.window="updateSpacing()"
>
    @foreach ($visibleActions as $action)
        {{ $action }}
    @endforeach

    @if ($moreActions->isNotEmpty())
        <div class="op-bottom-nav__menu">
            {{ \Filament\Actions\ActionGroup::make($moreActions->all())
                ->label('Mais')->icon('heroicon-o-ellipsis-horizontal')->color('gray')
                ->button()->dropdownPlacement('top-end')->dropdownTeleport()->livewire($this) }}
        </div>
    @endif
</nav>
