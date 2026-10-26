(function (config) {
    const validModes = ['light', 'dark', 'system'];
    const normalize = value => validModes.includes(value) ? value : 'system';

    return {
        mode: 'system',
        initialized: false,
        get resolvedMode() {
            return this.mode === 'system' ? (config.media.matches ? 'dark' : 'light') : this.mode;
        },
        init() {
            if (this.initialized) return this.apply();
            this.initialized = true;
            this.restore();
            config.media.addEventListener('change', () => {
                if (this.mode === 'system') {
                    this.apply();
                    config.emit(this.mode);
                }
            });
        },
        restore() {
            let stored = null;
            try { stored = config.read(); } catch { /* The theme still works if storage is unavailable. */ }
            this.accept(stored);
            config.emit(this.mode);
        },
        accept(value) {
            this.mode = normalize(value);
            try { config.write(this.mode); } catch { /* Keep the selection for the current page. */ }
            this.apply();
        },
        set(value) {
            this.accept(value);
            config.emit(this.mode);
        },
        apply() {
            const dark = this.resolvedMode === 'dark';
            config.root.classList.toggle('dark', dark);
            config.root.dataset.theme = this.resolvedMode;
            config.root.dataset.bpoTheme = this.mode;
            config.root.style.colorScheme = this.resolvedMode;
            config.selects().forEach(select => { select.value = this.mode; });
        },
    };
})
