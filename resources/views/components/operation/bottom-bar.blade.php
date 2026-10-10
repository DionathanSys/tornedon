@props(['columns' => 4, 'label' => 'Navegação principal'])

<nav
    class="operation-bottom-bar"
    aria-label="{{ $label }}"
    style="--operation-columns: {{ $columns }}"
    x-data="{
        observer: null,
        updateSpacing() {
            document.documentElement.style.setProperty('--operation-bottom-space', `${window.innerHeight - this.$el.getBoundingClientRect().top}px`);
        },
        init() {
            this.observer = new ResizeObserver(() => this.updateSpacing());
            this.observer.observe(this.$el);
            this.$nextTick(() => this.updateSpacing());
        },
        destroy() {
            this.observer?.disconnect();
            document.documentElement.style.removeProperty('--operation-bottom-space');
        },
    }"
    x-on:resize.window="updateSpacing()"
>
    {{ $slot }}
</nav>
