<div
    class="op-action-bar"
    role="region"
    aria-label="Ações da página"
    x-data="{
        observer: null,
        updateSpacing() {
            const navigation = document.querySelector('.op-bottom-nav');
            const offset = navigation ? window.innerHeight - navigation.getBoundingClientRect().top + 8 : 0;
            document.documentElement.style.setProperty('--op-nav-offset', `${offset}px`);
            document.documentElement.style.setProperty('--op-actions-height', `${this.$el.getBoundingClientRect().height}px`);
        },
        init() {
            this.observer = new ResizeObserver(() => this.updateSpacing());
            this.observer.observe(this.$el);
            this.$nextTick(() => {
                const navigation = document.querySelector('.op-bottom-nav');
                if (navigation) this.observer.observe(navigation);
                this.updateSpacing();
            });
        },
        destroy() {
            this.observer?.disconnect();
            document.documentElement.style.removeProperty('--op-nav-offset');
            document.documentElement.style.removeProperty('--op-actions-height');
        },
    }"
    x-on:resize.window="updateSpacing()"
>
    @foreach ($actions as $action)
        {{ $action }}
    @endforeach
</div>
