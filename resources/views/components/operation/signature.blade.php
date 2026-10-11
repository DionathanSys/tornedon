@props(['title' => 'Assinatura da OS', 'subtitle' => null])

<div x-data="operationSignatureCapture({
    state: $wire.entangle('{{ $attributes->wire('model')->value() }}'),
    statePath: @js($attributes->wire('model')->value()),
    modalOpen: $wire.entangle('showSignatureModal').live,
})">
    <x-mary-modal wire:model="showSignatureModal" :title="$title" :subtitle="$subtitle" box-class="max-w-lg" class="backdrop-blur-sm" persistent without-trap-focus x-show="!active" x-trap="modalOpen && !active" x-bind:inert="active || !modalOpen">
        <x-mary-form x-ref="nameForm" @submit.prevent="beginCapture()" no-separator>
            <x-mary-input label="Nome de quem está assinando" wire:model="signatureData.name" placeholder="Nome completo do signatário" maxlength="255" @input="$el.setCustomValidity('')" required />
            <p class="text-xs text-base-content/60">A próxima tela usará toda a área disponível para a assinatura.</p>
            <x-slot:actions>
                <x-mary-button label="Cancelar" @click="cancelCapture()" />
                <x-mary-button label="Iniciar assinatura" icon="o-arrows-pointing-out" class="btn-primary" type="submit" />
            </x-slot:actions>
        </x-mary-form>
    </x-mary-modal>

    <div
        x-ref="capture"
        x-show="active"
        x-cloak
        x-trap="active"
        role="dialog"
        aria-modal="true"
        aria-label="Coleta de assinatura em tela inteira"
        class="operation-signature-screen"
        :data-portrait="portrait"
        :aria-busy="starting || busy"
    >
        <div x-ref="workspace" class="operation-signature-workspace">
            <div wire:ignore class="operation-signature-board" :style="boardStyle">
                <canvas
                    x-ref="canvas"
                    aria-label="Assinatura do cliente"
                    class="block h-full w-full touch-none cursor-crosshair bg-white"
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
                <div class="pointer-events-none absolute inset-x-6 bottom-4 select-none text-center text-slate-500">
                    <div class="border-t border-dashed border-slate-400"></div>
                    <p class="mt-2 text-xs">Parte inferior · Assine acima desta linha</p>
                </div>
            </div>
            <div class="operation-signature-feedback" x-show="localError" x-text="localError" role="alert"></div>
            @error('signatureData.signature')
                <p class="operation-signature-feedback" role="alert">{{ $message }}</p>
            @enderror
            @error('signatureData.name')
                <p class="operation-signature-feedback" role="alert">{{ $message }}</p>
            @enderror
        </div>
        <div x-ref="toolbar" class="operation-signature-toolbar">
            <div class="operation-signature-toolbar-content" :style="toolbarStyle">
                <span class="min-w-0 flex-1 truncate text-xs" x-text="$wire.signatureData.name"></span>
                <div class="flex shrink-0 items-center gap-2">
                    <x-mary-button label="Cancelar" class="btn-ghost btn-sm" @click="cancelCapture()" x-bind:disabled="busy" />
                    <x-mary-button label="Limpar" icon="o-backspace" class="btn-ghost btn-sm" @click="clear(); localError = ''" x-bind:disabled="starting || busy" />
                    <x-mary-button label="Salvar" icon="o-check" class="btn-primary btn-sm" @click="submit()" x-bind:disabled="starting || busy" spinner="saveCustomerSignature" />
                </div>
            </div>
        </div>
    </div>
</div>
