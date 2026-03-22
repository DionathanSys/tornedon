@php
    $statePath = $getStatePath();
    $isDisabled = $isDisabled();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="signaturePadField({
            state: $wire.entangle('{{ $statePath }}').live,
            disabled: @js($isDisabled),
            height: @js($getCanvasHeight()),
        })"
        x-init="init()"
        class="space-y-3"
    >
        <div class="rounded-2xl border-2 border-sky-400 bg-gradient-to-br from-sky-50 via-white to-cyan-50 p-4 shadow-md ring-2 ring-sky-100 dark:border-sky-500/50 dark:from-slate-900 dark:via-slate-950 dark:to-sky-950/30 dark:ring-sky-500/20">
            <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <!-- <div>
                    <p class="text-sm font-medium text-gray-950 dark:text-white">Assinatura do cliente</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Em telas com toque, o cliente pode assinar com o dedo. Em desktop, a assinatura também pode ser feita com o mouse ou caneta.
                    </p>
                </div> -->

                <span
                    class="inline-flex items-center rounded-full bg-primary-50 px-2 py-1 text-[11px] font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-300"
                    x-text="isTouchDevice ? 'Modo toque detectado' : 'Modo mouse/caneta'"
                ></span>
            </div>

            <div class="mb-2 inline-flex items-center rounded-full border border-sky-400 bg-sky-100 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.12em] text-sky-900 shadow-sm dark:border-sky-400/40 dark:bg-sky-500/15 dark:text-sky-200">
                Assine dentro da caixa abaixo
            </div>

            <div
                class="rounded-2xl border-2 border-sky-300 bg-sky-100 p-2 shadow-[0_0_0_4px_rgba(14,165,233,0.10)] transition duration-200 dark:border-sky-500/40 dark:bg-sky-950/20"
                :class="isDrawing ? 'shadow-[0_0_0_8px_rgba(14,165,233,0.24)]' : ''"
            >
                <div class="overflow-hidden rounded-xl border-4 border-sky-600 bg-white shadow-inner dark:border-sky-400 dark:bg-slate-950">
                    <canvas
                        x-ref="canvas"
                        class="block w-full cursor-crosshair touch-none bg-white dark:bg-slate-950"
                        :style="`height: ${height}; background-image: linear-gradient(to bottom, rgba(255,255,255,1), rgba(240,249,255,1));`"
                        x-on:pointerdown="supportsPointerEvents && start($event)"
                        x-on:pointermove="supportsPointerEvents && move($event)"
                        x-on:pointerup.window="supportsPointerEvents && end($event)"
                        x-on:pointercancel.window="supportsPointerEvents && end($event)"
                        x-on:mousedown="!supportsPointerEvents && start($event)"
                        x-on:mousemove="!supportsPointerEvents && move($event)"
                        x-on:mouseup.window="!supportsPointerEvents && end($event)"
                        x-on:mouseleave="!supportsPointerEvents && end($event)"
                        x-on:touchstart.prevent="!supportsPointerEvents && start($event)"
                        x-on:touchmove.prevent="!supportsPointerEvents && move($event)"
                        x-on:touchend.window="!supportsPointerEvents && end($event)"
                        x-on:touchcancel.window="!supportsPointerEvents && end($event)"
                    ></canvas>
                </div>
            </div>

            <div class="mt-2 text-center text-xs font-medium text-sky-700 dark:text-sky-300">
                Toque, clique e arraste dentro da area azul para assinar.
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="fi-btn fi-btn-size-sm rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800"
                    x-on:click="clear()"
                    x-bind:disabled="disabled || !hasSignature"
                >
                    Limpar assinatura
                </button>

                <span class="text-xs text-gray-500 dark:text-gray-400" x-show="!disabled">
                    Desenhe na área acima.
                </span>
                <span class="text-xs text-gray-500 dark:text-gray-400" x-show="disabled">
                    A assinatura está bloqueada porque esta ordem de serviço não pode mais ser editada.
                </span>
            </div>
        </div>
    </div>
</x-dynamic-component>

@once
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('signaturePadField', (config) => ({
                state: config.state,
                disabled: config.disabled,
                height: config.height ?? '220px',
                isDrawing: false,
                hasSignature: false,
                isTouchDevice: false,
                supportsPointerEvents: false,
                activePointerId: null,
                ctx: null,
                resizeObserver: null,
                lastSerializedState: null,
                canvasWidth: null,
                canvasHeight: null,
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
                ensureCanvasReady() {
                    const canvas = this.$refs.canvas;
                    const width = canvas.clientWidth;
                    const height = canvas.clientHeight;

                    if (width <= 0 || height <= 0) {
                        window.requestAnimationFrame(() => {
                            this.ensureCanvasReady();
                        });

                        return;
                    }

                    this.setupCanvas(width, height);
                },
                resetCanvas(snapshot = null) {
                    const canvas = this.$refs.canvas;
                    const width = canvas.clientWidth;
                    const height = canvas.clientHeight;

                    if (width <= 0 || height <= 0) {
                        return;
                    }

                    this.canvasWidth = null;
                    this.canvasHeight = null;
                    this.setupCanvas(width, height, snapshot);
                },
                setupCanvas(width, height, snapshotOverride = undefined) {
                    const canvas = this.$refs.canvas;
                    const ratio = Math.max(window.devicePixelRatio || 1, 1);
                    const pixelWidth = Math.max(Math.floor(width * ratio), 1);
                    const pixelHeight = Math.max(Math.floor(height * ratio), 1);

                    if (
                        this.ctx &&
                        this.canvasWidth === pixelWidth &&
                        this.canvasHeight === pixelHeight
                    ) {
                        return;
                    }

                    const snapshot = snapshotOverride !== undefined
                        ? snapshotOverride
                        : (this.hasSignature ? this.$refs.canvas.toDataURL('image/png') : null);

                    canvas.width = pixelWidth;
                    canvas.height = pixelHeight;

                    this.ctx = canvas.getContext('2d');
                    this.ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                    this.ctx.lineCap = 'round';
                    this.ctx.lineJoin = 'round';
                    this.ctx.lineWidth = 2.2;
                    this.ctx.strokeStyle = '#111827';
                    this.ctx.clearRect(0, 0, width, height);
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
                    const width = canvas.clientWidth || rect.width;
                    const height = canvas.clientHeight || rect.height;
                    const scaleX = rect.width > 0 ? width / rect.width : 1;
                    const scaleY = rect.height > 0 ? height / rect.height : 1;

                    return {
                        x: this.clamp((point.clientX - rect.left) * scaleX, 0, width),
                        y: this.clamp((point.clientY - rect.top) * scaleY, 0, height),
                    };
                },
                clamp(value, min, max) {
                    return Math.min(Math.max(value, min), max);
                },
                resolvePoint(event) {
                    if (event.touches?.length) {
                        return event.touches[0];
                    }

                    if (event.changedTouches?.length) {
                        return event.changedTouches[0];
                    }

                    return event;
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
                    this.ctx.arc(point.x, point.y, 1.2, 0, Math.PI * 2);
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
                async clear() {
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
                        const width = canvas.clientWidth || canvas.getBoundingClientRect().width;
                        const height = canvas.clientHeight || canvas.getBoundingClientRect().height;

                        this.ctx.clearRect(0, 0, width, height);
                        this.ctx.drawImage(image, 0, 0, width, height);
                        this.hasSignature = true;
                    };

                    image.src = source;
                },
                restoreFromState() {
                    this.ensureCanvasReady();

                    if (!this.state) {
                        const canvas = this.$refs.canvas;
                        const width = canvas.clientWidth || canvas.getBoundingClientRect().width;
                        const height = canvas.clientHeight || canvas.getBoundingClientRect().height;

                        this.ctx?.clearRect(0, 0, width, height);
                        this.hasSignature = false;
                        this.lastSerializedState = null;

                        return;
                    }

                    this.restoreImage(this.state);
                    this.lastSerializedState = this.state;
                },
            }));
        });
    </script>
@endonce
