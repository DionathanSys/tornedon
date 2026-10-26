<script data-navigate-once>
    (() => {
        if (window.BpoTheme) {
            window.BpoTheme.apply();
            return;
        }
        window.BpoTheme = ({!! file_get_contents(resource_path('js/bpo-theme.js')) !!})({
            root: document.documentElement,
            media: window.matchMedia('(prefers-color-scheme: dark)'),
            read: () => localStorage.getItem('theme'),
            write: value => localStorage.setItem('theme', value),
            selects: () => document.querySelectorAll('[data-bpo-theme-select]'),
            emit: value => window.dispatchEvent(new CustomEvent('theme-changed', { detail: value })),
        });
        window.addEventListener('theme-changed', event => window.BpoTheme.accept(event.detail));
        window.addEventListener('storage', event => {
            if (event.key === 'theme' || event.key === null) window.BpoTheme.restore();
        });
        document.addEventListener('DOMContentLoaded', () => window.BpoTheme.apply());
        document.addEventListener('livewire:navigated', () => window.BpoTheme.apply());
        window.BpoTheme.init();
    })();
</script>
