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
        <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-950 dark:text-white">Assinatura do cliente</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Em telas com toque, o cliente pode assinar com o dedo. Em desktop, a assinatura também pode ser feita com o mouse ou caneta.
                    </p>
                </div>

                <span
                    class="inline-flex items-center rounded-full bg-primary-50 px-2 py-1 text-[11px] font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-300"
                    x-text="isTouchDevice ? 'Modo toque detectado' : 'Modo mouse/caneta'"
                ></span>
            </div>

            <div class="overflow-hidden rounded-lg border border-dashed border-gray-300 bg-gray-50 dark:border-gray-600 dark:bg-gray-950/40">
                <canvas
                    x-ref="canvas"
                    class="block w-full touch-none"
                    :style="`height: ${height}`"
                    x-on:pointerdown="start($event)"
                    x-on:pointermove="move($event)"
                    x-on:pointerup.window="end($event)"
                    x-on:pointercancel.window="end($event)"
                ></canvas>
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
                    Desenhe na área acima. A assinatura é salva quando o traço termina.
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
                activePointerId: null,
                ctx: null,
                resizeObserver: null,
                lastSerializedState: null,
                init() {
                    this.isTouchDevice = window.matchMedia?.('(pointer: coarse)')?.matches || (navigator.maxTouchPoints ?? 0) > 0;

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
                    const rect = canvas.getBoundingClientRect();

                    if (rect.width <= 0) {
                        window.requestAnimationFrame(() => {
                            this.ensureCanvasReady();
                        });

                        return;
                    }

                    this.setupCanvas(rect.width);
                },
                setupCanvas(width) {
                    const canvas = this.$refs.canvas;
                    const ratio = Math.max(window.devicePixelRatio || 1, 1);
                    const height = parseInt(this.height, 10) || 220;

                    canvas.width = Math.max(Math.floor(width * ratio), 1);
                    canvas.height = Math.max(Math.floor(height * ratio), 1);

                    this.ctx = canvas.getContext('2d');
                    this.ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                    this.ctx.lineCap = 'round';
                    this.ctx.lineJoin = 'round';
                    this.ctx.lineWidth = 2.2;
                    this.ctx.strokeStyle = '#111827';
                    this.ctx.clearRect(0, 0, width, height);
                },
                coordinates(event) {
                    const rect = this.$refs.canvas.getBoundingClientRect();

                    return {
                        x: event.clientX - rect.left,
                        y: event.clientY - rect.top,
                    };
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

                    this.activePointerId = event.pointerId;
                    this.$refs.canvas.setPointerCapture?.(event.pointerId);
                    this.isDrawing = true;

                    const point = this.coordinates(event);
                    this.ctx.beginPath();
                    this.ctx.moveTo(point.x, point.y);
                    this.drawDot(point);
                    this.hasSignature = true;
                },
                move(event) {
                    if (this.disabled || !this.isDrawing || event.pointerId !== this.activePointerId) {
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

                    if (event && this.activePointerId !== null && event.pointerId !== this.activePointerId) {
                        return;
                    }

                    this.$refs.canvas.releasePointerCapture?.(this.activePointerId);
                    this.isDrawing = false;
                    this.activePointerId = null;
                    this.ctx.closePath();
                    this.syncState();
                },
                clear() {
                    if (this.disabled) {
                        return;
                    }

                    this.ensureCanvasReady();

                    const canvas = this.$refs.canvas;
                    const rect = canvas.getBoundingClientRect();
                    const height = parseInt(this.height, 10) || 220;

                    this.ctx.clearRect(0, 0, rect.width, height);
                    this.lastSerializedState = null;
                    this.state = null;
                    this.hasSignature = false;
                },
                syncState() {
                    this.lastSerializedState = this.hasSignature ? this.$refs.canvas.toDataURL('image/png') : null;
                    this.state = this.lastSerializedState;
                },
                restoreFromState() {
                    this.ensureCanvasReady();

                    if (!this.state) {
                        const rect = this.$refs.canvas.getBoundingClientRect();
                        const height = parseInt(this.height, 10) || 220;

                        this.ctx?.clearRect(0, 0, rect.width, height);
                        this.hasSignature = false;
                        this.lastSerializedState = null;

                        return;
                    }

                    const image = new Image();

                    image.onload = () => {
                        const rect = this.$refs.canvas.getBoundingClientRect();
                        const height = parseInt(this.height, 10) || 220;

                        this.ctx.clearRect(0, 0, rect.width, height);
                        this.ctx.drawImage(image, 0, 0, rect.width, height);
                        this.hasSignature = true;
                        this.lastSerializedState = this.state;
                    };

                    image.src = this.state;
                },
            }));
        });
    </script>
@endonce
