const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

export function fabBounds(width, height, size, navigationTop = height, safe = {}) {
    const left = Math.max(8, safe.left || 0), top = Math.max(8, safe.top || 0);
    const right = Math.max(8, safe.right || 0), bottom = Math.max(8, safe.bottom || 0);
    return { minX: left, minY: top, maxX: Math.max(left, width - size - right), maxY: Math.max(top, Math.min(height - bottom, navigationTop - 8) - size) };
}

export function clampFabPosition(x, y, bounds) {
    return { x: clamp(x, bounds.minX, bounds.maxX), y: clamp(y, bounds.minY, bounds.maxY) };
}

export function normalizeFabPosition(x, y, bounds) {
    const point = clampFabPosition(x, y, bounds);
    return { x: (point.x - bounds.minX) / (bounds.maxX - bounds.minX || 1), y: (point.y - bounds.minY) / (bounds.maxY - bounds.minY || 1) };
}

export function restoreFabPosition(position, bounds) {
    return clampFabPosition(bounds.minX + position.x * (bounds.maxX - bounds.minX), bounds.minY + position.y * (bounds.maxY - bounds.minY), bounds);
}

export function fabMenuPosition(button, width, height, viewportWidth, viewportHeight) {
    const top = button.top >= height + 16 ? button.top - height - 8 : button.bottom + 8;
    return { x: clamp(button.right - width, 8, Math.max(8, viewportWidth - width - 8)), y: clamp(top, 8, Math.max(8, viewportHeight - height - 8)) };
}

export function operationFab(config) {
    return {
        hidden: false,
        menuOpen: false,
        dragging: false,
        position: null,
        x: null,
        y: null,
        pointer: null,
        longPressed: false,
        ignoreClickUntil: 0,
        pressTimer: null,
        menuStyle: {},
        observer: null,
        onResize: null,
        destroyed: false,
        menuFrame: null,

        get positionStyle() {
            return this.x === null ? {} : { left: `${this.x}px`, top: `${this.y}px`, right: 'auto', bottom: 'auto' };
        },

        init() {
            try {
                const saved = JSON.parse(localStorage.getItem(config.key) || 'null');
                this.hidden = saved?.hidden === true;
                if (Number.isFinite(saved?.position?.x) && Number.isFinite(saved?.position?.y)) {
                    this.position = { x: clamp(saved.position.x, 0, 1), y: clamp(saved.position.y, 0, 1) };
                }
            } catch { /* Use defaults if browser storage is unavailable or invalid. */ }
            this.onResize = () => {
                this.finishPointer(null, true);
                this.applyPosition();
            };
            window.addEventListener('resize', this.onResize);
            this.$nextTick(() => {
                if (this.destroyed) return;
                this.applyPosition();
                const navigation = document.querySelector('.operation-bottom-bar');
                if (navigation) {
                    this.observer = new ResizeObserver(() => this.applyPosition());
                    this.observer.observe(navigation);
                }
            });
        },

        bounds() {
            const size = this.$refs.button?.offsetWidth || 56;
            const navigation = document.querySelector('.operation-bottom-bar');
            const style = window.getComputedStyle(this.$refs.button);
            const safe = Object.fromEntries(['top', 'right', 'bottom', 'left'].map(side => [side, parseFloat(style.getPropertyValue(`--operation-fab-safe-${side}`)) || 0]));
            return fabBounds(window.innerWidth, window.innerHeight, size, navigation?.getBoundingClientRect().top ?? window.innerHeight, safe);
        },

        savePreference() {
            try { localStorage.setItem(config.key, JSON.stringify({ hidden: this.hidden, position: this.position })); } catch { /* Gestures still work without storage. */ }
        },

        applyPosition() {
            if (this.destroyed) return;
            if (this.position && !this.dragging) Object.assign(this, restoreFabPosition(this.position, this.bounds()));
            if (this.menuOpen) this.placeMenu();
        },

        pointerDown(event) {
            if (event.button !== 0 || this.pointer) return;
            const rect = this.$refs.button.getBoundingClientRect();
            this.ignoreClickUntil = 0;
            this.longPressed = false;
            this.dragging = false;
            this.pointer = { id: event.pointerId, x: event.clientX, y: event.clientY, left: rect.left, top: rect.top };
            this.$refs.button.setPointerCapture?.(event.pointerId);
            this.pressTimer = window.setTimeout(() => {
                if (this.pointer && !this.dragging) this.openOptions();
            }, 600);
        },

        pointerMove(event) {
            if (!this.pointer || event.pointerId !== this.pointer.id || this.longPressed) return;
            const dx = event.clientX - this.pointer.x, dy = event.clientY - this.pointer.y;
            if (!this.dragging && Math.hypot(dx, dy) < 8) return;
            this.dragging = true;
            this.menuOpen = false;
            window.clearTimeout(this.pressTimer);
            event.preventDefault();
            Object.assign(this, clampFabPosition(this.pointer.left + dx, this.pointer.top + dy, this.bounds()));
        },

        finishPointer(event = null, cancelled = false) {
            if (!this.pointer || (event && event.pointerId !== this.pointer.id)) return;
            window.clearTimeout(this.pressTimer);
            if (this.dragging) {
                this.position = normalizeFabPosition(this.x, this.y, this.bounds());
                this.savePreference();
            }
            if (this.dragging || this.longPressed || cancelled) this.ignoreClickUntil = Date.now() + 700;
            const id = this.pointer.id;
            this.pointer = null;
            this.dragging = false;
            if (this.$refs.button.hasPointerCapture?.(id)) this.$refs.button.releasePointerCapture(id);
        },

        activate(event) {
            if (event.detail !== 0 && Date.now() < this.ignoreClickUntil) {
                event.preventDefault();
                event.stopImmediatePropagation();
                this.ignoreClickUntil = 0;
                return;
            }
            this.activatePrimary();
        },

        activatePrimary() {
            this.closeMenu();
            if (config.kind) this.$dispatch(config.event || 'operation-create-record', { kind: config.kind });
            else if (config.method) this.$wire[config.method]();
        },

        openOptions() {
            window.clearTimeout(this.pressTimer);
            this.longPressed = true;
            this.ignoreClickUntil = Date.now() + 700;
            this.menuOpen = true;
            this.$nextTick(() => this.placeMenu());
        },

        placeMenu() {
            if (this.destroyed || !this.$refs.options || !this.$refs.button) return;
            const menu = this.$refs.options;
            const size = menu.getBoundingClientRect();
            if (!size.width || !size.height) {
                if (this.menuFrame === null) this.menuFrame = window.requestAnimationFrame(() => {
                    this.menuFrame = null;
                    if (this.menuOpen) this.placeMenu();
                });
                return;
            }
            const navigation = document.querySelector('.operation-bottom-bar');
            const point = fabMenuPosition(this.$refs.button.getBoundingClientRect(), size.width, size.height, window.innerWidth, navigation?.getBoundingClientRect().top ?? window.innerHeight);
            this.menuStyle = { left: `${point.x}px`, top: `${point.y}px` };
        },

        closeMenu() {
            this.menuOpen = false;
            if (this.menuFrame !== null) window.cancelAnimationFrame(this.menuFrame);
            this.menuFrame = null;
        },

        hideFab() {
            this.finishPointer(null, true);
            this.closeMenu();
            this.hidden = true;
            this.savePreference();
        },

        showFab() {
            this.hidden = false;
            this.savePreference();
            this.$nextTick(() => {
                this.applyPosition();
                this.$refs.button.focus();
            });
        },

        resetPosition() {
            this.finishPointer(null, true);
            this.position = null;
            this.x = null;
            this.y = null;
            this.savePreference();
            this.closeMenu();
        },

        destroy() {
            this.destroyed = true;
            this.pointer = null;
            window.clearTimeout(this.pressTimer);
            if (this.menuFrame !== null) window.cancelAnimationFrame(this.menuFrame);
            this.observer?.disconnect();
            window.removeEventListener('resize', this.onResize);
        },
    };
}
