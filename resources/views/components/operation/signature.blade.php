<div class="space-y-3">
    <p class="text-xs text-base-content/60">Assine dentro da área abaixo, usando o dedo, mouse ou caneta.</p>
    <div
        wire:ignore
        x-data="signaturePadField({ state: $wire.entangle('{{ $attributes->wire('model')->value() }}'), disabled: false })"
        class="space-y-3"
    >
        <div class="overflow-hidden rounded-box border border-base-300 bg-white">
            <canvas
                x-ref="canvas"
                aria-label="Assinatura do cliente"
                class="block h-60 w-full touch-none cursor-crosshair bg-white"
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
        <x-mary-button label="Limpar assinatura" icon="o-backspace" class="btn-ghost btn-sm" @click="clear()" />
    </div>
    @error($attributes->wire('model')->value())
        <p class="text-xs text-error">{{ $message }}</p>
    @enderror
</div>
