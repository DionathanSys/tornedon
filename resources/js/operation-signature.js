const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

export function signatureLayout(width, height, aspectRatio = null) {
    const rotated = height > width;
    const availableWidth = rotated ? height : width;
    const availableHeight = rotated ? width : height;
    const ratio = aspectRatio ?? availableWidth / availableHeight;
    const logicalWidth = Math.min(availableWidth, availableHeight * ratio);

    return { rotated, width: logicalWidth, height: logicalWidth / ratio };
}

export function signaturePoint(clientX, clientY, rect, width, height, rotated) {
    const x = clamp((clientX - rect.left) / rect.width, 0, 1);
    const y = clamp((clientY - rect.top) / rect.height, 0, 1);

    return rotated ? { x: (1 - y) * width, y: x * height } : { x: x * width, y: y * height };
}

export function operationSignatureCapture(config) {
    return {
        ...window.signaturePadField({ state: config.state, disabled: true }),
        modalOpen: config.modalOpen,
        statePath: config.statePath,
        active: false,
        starting: false,
        busy: false,
        portrait: window.innerHeight > window.innerWidth,
        rotated: false,
        boardStyle: 'width: 0; height: 0;',
        toolbarStyle: '',
        localError: '',
        canonicalWidth: null,
        canonicalHeight: null,
        captureElement: null,
        captureRequest: 0,
        orientationAttempted: false,
        pageLocked: false,
        overflowBefore: '',
        originFocus: null,
        onFullscreenChange: null,
        onResize: null,
        exitingFullscreen: false,

        init() {
            this.captureElement = this.$refs.capture;
            this.originFocus = document.activeElement;
            this.isTouchDevice = (navigator.maxTouchPoints ?? 0) > 0 || window.matchMedia('(pointer: coarse)').matches;
            this.supportsPointerEvents = 'PointerEvent' in window;
            this.$watch('modalOpen', open => { if (!open) this.leaveCapture(); });
            this.$watch('state', value => {
                if (this.active && value !== this.lastSerializedState) this.restoreFromState();
            });
            this.resizeObserver = new ResizeObserver(() => this.updateLayout());
            this.resizeObserver.observe(this.$refs.workspace);
            this.onResize = () => this.updateLayout();
            this.onFullscreenChange = () => {
                if (document.fullscreenElement !== this.captureElement) this.releaseOrientation();
                this.updateLayout();
            };
            window.addEventListener('resize', this.onResize);
            document.addEventListener('fullscreenchange', this.onFullscreenChange);
        },

        async beginCapture() {
            if (this.active || this.starting) return;
            const input = this.$refs.nameForm.querySelector('input');
            input.setCustomValidity(input.value.trim() ? '' : 'Informe o nome de quem está assinando.');
            if (!this.$refs.nameForm.reportValidity()) return;

            this.$wire.set('signatureData.name', input.value.trim(), false);
            input.blur();
            const request = ++this.captureRequest;
            this.active = true;
            this.starting = true;
            this.disabled = true;
            this.localError = '';
            this.overflowBefore = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            document.documentElement.classList.add('operation-signature-capturing');
            document.body.classList.add('operation-signature-capturing');
            this.pageLocked = true;
            // The fullscreen request must run within this user gesture, before any server call.
            this.captureElement.style.display = 'grid';

            try {
                if (!document.fullscreenElement && this.captureElement.requestFullscreen) {
                    await this.captureElement.requestFullscreen({ navigationUI: 'hide' });
                }
            } catch {
                // The fixed viewport overlay also works when fullscreen is unavailable.
            }

            if (this.destroyed || !this.active || request !== this.captureRequest) {
                this.releaseBrowserMode();
                return;
            }

            if (document.fullscreenElement === this.captureElement && this.isTouchDevice && screen.orientation?.lock) {
                this.orientationAttempted = true;
                try { await screen.orientation.lock('portrait-primary'); } catch { /* Keep the lateral layout. */ }
            }

            // Let the mobile keyboard close before choosing the permanent bitmap dimensions.
            if (this.isTouchDevice) await new Promise(resolve => window.setTimeout(resolve, 250));

            if (this.destroyed || !this.active || request !== this.captureRequest) {
                this.releaseBrowserMode();
                return;
            }

            this.starting = false;
            await this.updateLayout();
            if (!this.destroyed && this.active) this.disabled = false;
        },

        async updateLayout() {
            if (this.destroyed || !this.active || this.starting) return;
            const screenRect = this.captureElement.getBoundingClientRect();
            this.portrait = screenRect.height > screenRect.width;
            await this.$nextTick();
            if (this.destroyed || !this.active || !this.$refs.workspace) return;
            const rect = this.$refs.workspace.getBoundingClientRect();
            if (rect.width <= 0 || rect.height <= 0) return;

            const layout = signatureLayout(rect.width, rect.height, this.canonicalWidth ? this.canonicalWidth / this.canonicalHeight : null);
            if (!this.canonicalWidth) {
                const ratio = Math.max(window.devicePixelRatio || 1, 1);
                this.canonicalWidth = Math.max(1, Math.floor(layout.width * ratio));
                this.canonicalHeight = Math.max(1, Math.floor(layout.height * ratio));
                this.$refs.canvas.width = this.canonicalWidth;
                this.$refs.canvas.height = this.canonicalHeight;
                this.ctx = this.$refs.canvas.getContext('2d');
                this.ctx.lineCap = 'round';
                this.ctx.lineJoin = 'round';
                this.drawingScale = ratio;
                this.ctx.lineWidth = 2.2 * ratio;
                this.ctx.strokeStyle = '#111827';
                this.canvasWidth = this.canonicalWidth;
                this.canvasHeight = this.canonicalHeight;
                if (this.state) this.restoreImage(this.state);
            }

            this.rotated = layout.rotated;
            this.boardStyle = `width: ${layout.width}px; height: ${layout.height}px; transform: translate(-50%, -50%)${layout.rotated ? ' rotate(-90deg)' : ''};`;
            this.toolbarStyle = this.portrait
                ? `width: ${this.$refs.toolbar.getBoundingClientRect().height}px; height: 56px; transform: translate(-50%, -50%) rotate(-90deg);`
                : '';
        },

        ensureCanvasReady() {
            if (!this.ctx && this.active && !this.starting && !this.destroyed) this.updateLayout();
        },

        resetCanvas(snapshot = null) {
            if (this.destroyed || !this.ctx || !this.$refs.canvas) return;
            this.ctx.clearRect(0, 0, this.canonicalWidth, this.canonicalHeight);
            if (snapshot) this.restoreImage(snapshot);
        },

        coordinates(event) {
            const point = this.resolvePoint(event);
            return signaturePoint(point.clientX, point.clientY, this.$refs.canvas.getBoundingClientRect(), this.canonicalWidth, this.canonicalHeight, this.rotated);
        },

        async submit() {
            if (this.busy || this.starting || !this.active) return;
            if (!this.hasSignature) {
                this.localError = 'Faça a assinatura na área indicada.';
                return;
            }
            this.localError = '';
            this.busy = true;
            this.disabled = true;
            this.end();
            this.syncState();
            this.$wire.set(this.statePath, this.state, false);
            try {
                await this.$wire.saveCustomerSignature();
            } catch {
                if (!this.destroyed) this.localError = 'Não foi possível salvar a assinatura. Tente novamente.';
            } finally {
                if (!this.destroyed) {
                    this.busy = false;
                    this.disabled = false;
                    if (!this.modalOpen) this.leaveCapture();
                }
            }
        },

        cancelCapture() {
            if (this.busy) return;
            this.leaveCapture();
            this.$wire.showSignatureModal = false;
        },

        releaseOrientation() {
            if (this.orientationAttempted) {
                try { screen.orientation?.unlock?.(); } catch { /* Not supported on every browser. */ }
                this.orientationAttempted = false;
            }
        },

        releaseBrowserMode() {
            this.releaseOrientation();
            if (document.fullscreenElement === this.captureElement && document.exitFullscreen && !this.exitingFullscreen) {
                this.exitingFullscreen = true;
                try {
                    Promise.resolve(document.exitFullscreen()).catch(() => {}).finally(() => { this.exitingFullscreen = false; });
                } catch { this.exitingFullscreen = false; }
            }
            if (this.pageLocked) {
                document.body.style.overflow = this.overflowBefore;
                document.documentElement.classList.remove('operation-signature-capturing');
                document.body.classList.remove('operation-signature-capturing');
                this.pageLocked = false;
            }
        },

        leaveCapture() {
            this.captureRequest++;
            this.active = false;
            this.starting = false;
            this.disabled = true;
            this.isDrawing = false;
            this.activePointerId = null;
            this.releaseBrowserMode();
        },

        destroy() {
            this.destroyed = true;
            this.leaveCapture();
            this.resizeObserver?.disconnect();
            window.removeEventListener('resize', this.onResize);
            document.removeEventListener('fullscreenchange', this.onFullscreenChange);
            if (this.originFocus?.isConnected) this.originFocus.focus();
        },
    };
}
