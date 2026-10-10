<div
    {{ $attributes->class(['operation-ui']) }}
    x-data="{
        dark: document.documentElement.classList.contains('dark'),
        observer: null,
        init() {
            this.observer = new MutationObserver(() => {
                this.dark = document.documentElement.classList.contains('dark');
            });
            this.observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        },
        destroy() { this.observer?.disconnect(); },
    }"
    :data-theme="dark ? 'dark' : 'light'"
>
    {{ $slot }}
</div>
