<script>
    (() => {
        const registerSignaturePadField = () => {
            if (!window.Alpine || window.signaturePadFieldRegistered) {
                return;
            }

            window.signaturePadFieldRegistered = true;

            const signaturePadField = (config) => ({
                state: config.state,
                disabled: config.disabled,
                isTouchDevice: false,
                isDrawing: false,
                hasSignature: false,
                supportsPointerEvents: false,
                activePointerId: null,
                ctx: null,
                resizeObserver: null,
                lastSerializedState: null,
                canvasWidth: null,
                canvasHeight: null,
                drawingScale: 1,
                pointerSequence: 0,

                init() {
                    this.isTouchDevice = window.matchMedia?.('(pointer: coarse)')?.matches || (navigator.maxTouchPoints ?? 0) > 0;
                    this.supportsPointerEvents = 'PointerEvent' in window;

                    this.$nextTick(() => {
                        this.ensureCanvasReady();
                        this.restoreFromState();

                        this.$watch('state', (value) => {
                            if (value === this.lastSerializedState) {
                                return;
                            }

                            this.restoreFromState();
                        });

                        if (window.ResizeObserver) {
                            this.resizeObserver = new ResizeObserver(() => {
                                this.ensureCanvasReady();
                                this.restoreFromState();
                            });

                            this.resizeObserver.observe(this.$root);
                        }
                    });
                },

                destroy() {
                    this.resizeObserver?.disconnect();
                },

                ensureCanvasReady() {
                    const canvas = this.$refs.canvas;
                    const rect = canvas.getBoundingClientRect();

                    if (rect.width <= 0 || rect.height <= 0) {
                        window.requestAnimationFrame(() => this.ensureCanvasReady());

                        return;
                    }

                    this.setupCanvas(rect.width, rect.height);
                },

                resetCanvas(snapshot = null) {
                    const canvas = this.$refs.canvas;
                    const rect = canvas.getBoundingClientRect();

                    if (rect.width <= 0 || rect.height <= 0) {
                        return;
                    }

                    this.canvasWidth = null;
                    this.canvasHeight = null;
                    this.setupCanvas(rect.width, rect.height, snapshot);
                },

                setupCanvas(width, height, snapshotOverride = undefined) {
                    const canvas = this.$refs.canvas;
                    const ratio = Math.max(window.devicePixelRatio || 1, 1);
                    const pixelWidth = Math.max(Math.floor(width * ratio), 1);
                    const pixelHeight = Math.max(Math.floor(height * ratio), 1);

                    if (this.ctx && this.canvasWidth === pixelWidth && this.canvasHeight === pixelHeight) {
                        return;
                    }

                    const snapshot = snapshotOverride !== undefined
                        ? snapshotOverride
                        : (this.hasSignature ? canvas.toDataURL('image/png') : null);

                    canvas.width = pixelWidth;
                    canvas.height = pixelHeight;
                    this.ctx = canvas.getContext('2d');
                    this.ctx.setTransform(1, 0, 0, 1, 0, 0);
                    this.ctx.lineCap = 'round';
                    this.ctx.lineJoin = 'round';
                    this.drawingScale = pixelWidth / width;
                    this.ctx.lineWidth = 2.2 * this.drawingScale;
                    this.ctx.strokeStyle = '#111827';
                    this.ctx.clearRect(0, 0, canvas.width, canvas.height);
                    this.canvasWidth = pixelWidth;
                    this.canvasHeight = pixelHeight;

                    if (snapshot) {
                        this.restoreImage(snapshot);
                    }
                },

                coordinates(event) {
                    const canvas = this.$refs.canvas;
                    const rect = canvas.getBoundingClientRect();
                    const point = this.resolvePoint(event);
                    const scaleX = rect.width > 0 ? canvas.width / rect.width : 1;
                    const scaleY = rect.height > 0 ? canvas.height / rect.height : 1;

                    return {
                        x: this.clamp((point.clientX - rect.left) * scaleX, 0, canvas.width),
                        y: this.clamp((point.clientY - rect.top) * scaleY, 0, canvas.height),
                    };
                },

                clamp(value, min, max) {
                    return Math.min(Math.max(value, min), max);
                },

                resolvePoint(event) {
                    return event.touches?.[0] ?? event.changedTouches?.[0] ?? event;
                },

                resolvePointerId(event) {
                    if (event.pointerId !== undefined) {
                        return `pointer-${event.pointerId}`;
                    }

                    if (event.changedTouches?.length) {
                        return `touch-${event.changedTouches[0].identifier}`;
                    }

                    if (event.touches?.length) {
                        return `touch-${event.touches[0].identifier}`;
                    }

                    return `mouse-${this.pointerSequence}`;
                },

                drawDot(point) {
                    this.ctx.beginPath();
                    this.ctx.arc(point.x, point.y, 1.2 * this.drawingScale, 0, Math.PI * 2);
                    this.ctx.fillStyle = '#111827';
                    this.ctx.fill();
                    this.ctx.closePath();
                },

                start(event) {
                    if (this.disabled) {
                        return;
                    }

                    event.preventDefault();
                    this.ensureCanvasReady();

                    if (!this.ctx) {
                        return;
                    }

                    this.pointerSequence += 1;
                    this.activePointerId = this.resolvePointerId(event);
                    const point = this.coordinates(event);

                    if (event.pointerId !== undefined) {
                        this.$refs.canvas.setPointerCapture?.(event.pointerId);
                    }

                    this.ctx.beginPath();
                    this.ctx.moveTo(point.x, point.y);
                    this.drawDot(point);
                    this.isDrawing = true;
                    this.hasSignature = true;
                },

                move(event) {
                    if (this.disabled || !this.isDrawing || this.resolvePointerId(event) !== this.activePointerId) {
                        return;
                    }

                    event.preventDefault();
                    const point = this.coordinates(event);
                    this.ctx.lineTo(point.x, point.y);
                    this.ctx.stroke();
                    this.hasSignature = true;
                },

                end(event = null) {
                    if (!this.isDrawing) {
                        return;
                    }

                    if (event && this.activePointerId !== null && this.resolvePointerId(event) !== this.activePointerId) {
                        return;
                    }

                    if (event?.pointerId !== undefined) {
                        this.$refs.canvas.releasePointerCapture?.(event.pointerId);
                    }

                    this.isDrawing = false;
                    this.activePointerId = null;
                    this.ctx.closePath();
                    this.syncState();
                },

                clear() {
                    if (this.disabled) {
                        return;
                    }

                    this.isDrawing = false;
                    this.activePointerId = null;
                    this.ctx?.closePath();
                    this.lastSerializedState = null;
                    this.state = null;
                    this.hasSignature = false;
                    this.resetCanvas(null);
                },

                syncState() {
                    this.lastSerializedState = this.hasSignature ? this.$refs.canvas.toDataURL('image/png') : null;
                    this.state = this.lastSerializedState;
                },

                restoreImage(source) {
                    if (!source) {
                        return;
                    }

                    const image = new Image();

                    image.onload = () => {
                        const canvas = this.$refs.canvas;

                        this.ctx.clearRect(0, 0, canvas.width, canvas.height);
                        this.ctx.drawImage(image, 0, 0, canvas.width, canvas.height);
                        this.hasSignature = true;
                    };

                    image.src = source;
                },

                restoreFromState() {
                    this.ensureCanvasReady();

                    if (!this.state) {
                        const canvas = this.$refs.canvas;

                        this.ctx?.clearRect(0, 0, canvas.width, canvas.height);
                        this.hasSignature = false;
                        this.lastSerializedState = null;

                        return;
                    }

                    this.restoreImage(this.state);
                    this.lastSerializedState = this.state;
                },
            });

            window.signaturePadField = signaturePadField;
            window.Alpine.data('signaturePadField', signaturePadField);
        };

        if (window.Alpine) {
            registerSignaturePadField();
        } else {
            document.addEventListener('alpine:init', registerSignaturePadField, { once: true });

            let attempts = 0;
            const waitForAlpine = () => {
                if (window.Alpine) {
                    registerSignaturePadField();

                    return;
                }

                if (attempts < 100) {
                    attempts += 1;
                    window.setTimeout(waitForAlpine, 50);
                }
            };

            waitForAlpine();
        }
    })();
</script>
