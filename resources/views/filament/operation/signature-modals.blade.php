<x-mary-modal wire:model="showSignatureLinkModal" title="Link para assinatura" subtitle="Envie o link ao cliente para acessar a OS e assinar." box-class="max-w-lg" class="backdrop-blur-sm">
    @if ($signatureLink)
        <div class="space-y-4" wire:key="operation-signature-link-{{ hash('sha256', $signatureLink['url']) }}" x-data="{
            copied: false,
            async copyLink() {
                try {
                    await navigator.clipboard.writeText(this.$refs.signatureLinkInput.value);
                    this.copied = true;
                } catch {
                    this.$refs.signatureLinkInput.focus();
                    this.$refs.signatureLinkInput.select();
                    this.copied = document.execCommand?.('copy') ?? false;
                }
            },
        }">
            <x-mary-input label="Link de assinatura" :value="$signatureLink['url']" readonly x-ref="signatureLinkInput" @click="$el.select()" />
            <p class="text-xs text-base-content/60">Válido até {{ $signatureLink['expires_at'] }}, para uma única assinatura. Gerar outro link invalida o anterior.</p>
            <div class="flex flex-wrap gap-3">
                <x-mary-button label="Copiar link" icon="o-clipboard" class="btn-primary btn-sm" @click="copyLink()" />
                <x-mary-button label="Abrir link" icon="o-arrow-top-right-on-square" :link="$signatureLink['url']" external class="btn-ghost btn-sm" />
            </div>
            <p x-show="copied" x-cloak class="text-xs text-success" role="status">Link copiado.</p>
        </div>
    @endif
    <x-slot:actions>
        <x-mary-button label="Fechar" @click="$wire.showSignatureLinkModal = false" />
    </x-slot:actions>
</x-mary-modal>

<x-mary-modal wire:model="showReplaceSignatureModal" title="Substituir a assinatura?" subtitle="A nova coleta substituirá a assinatura atual desta OS." class="backdrop-blur-sm">
    <p class="text-sm text-base-content/70">Assinatura atual: {{ $order['signed_by_name'] ?? 'Nome não informado' }} · {{ $order['signed_at'] ?? 'Data não informada' }}.</p>
    <x-slot:actions>
        <x-mary-button label="Cancelar" @click="$wire.showReplaceSignatureModal = false" />
        <x-mary-button label="Continuar com nova assinatura" class="btn-primary" wire:click="confirmSignatureReplacement" spinner="confirmSignatureReplacement" />
    </x-slot:actions>
</x-mary-modal>

@if ($showSignatureModal)
    <x-operation.signature :title="$replaceSignature ? 'Assinar novamente' : 'Assinar agora'" :subtitle="'OS #'.($order['number'] ?? '')" wire:model="signatureData.signature" />
@endif

<x-mary-modal wire:model="showSignatureViewModal" title="Assinatura da OS" :subtitle="'OS #'.($order['number'] ?? '')" box-class="max-w-2xl" class="backdrop-blur-sm">
    @if ($signaturePreview)
        <div class="space-y-4">
            <div class="rounded-box border border-base-300 bg-white p-4">
                <img src="{{ $signaturePreview['image'] }}" alt="Assinatura do cliente" class="mx-auto max-h-60 max-w-full object-contain" />
            </div>
            <p class="text-sm">{{ $signaturePreview['name'] ?: 'Nome não informado' }}</p>
            <p class="text-xs text-base-content/60">Assinada em {{ $signaturePreview['signed_at'] ?: 'Data não informada' }}.</p>
        </div>
    @endif
    <x-slot:actions>
        <x-mary-button label="Fechar" @click="$wire.showSignatureViewModal = false" />
    </x-slot:actions>
</x-mary-modal>
