<div @if($poll) wire:poll.5s="refreshPayment" @endif>
    @if($visible)
        <x-operation.panel title="Pagamento no atendimento">
            <div class="space-y-4" aria-live="polite">
                <p @class(['text-lg font-semibold', 'text-success' => $paid])>
                    @if($paid)<x-mary-icon name="o-check-circle" class="inline h-6 w-6" />@endif
                    {{ $status }}
                </p>
                <p class="text-2xl font-bold">{{ $amount }}</p>
                @if($paid)
                    <p>Recebimento registrado. {{ $paidAt }}</p>
                @endif
                @if($canGenerate)
                    <p class="text-sm text-base-content/60">Ao gerar, os dados serão salvos e a ordem será encerrada e faturada.</p>
                    <x-mary-button label="Gerar cobrança PIX" icon="o-qr-code" class="btn-primary w-full" wire:click="$parent.generatePixPayment()" wire:loading.attr="disabled" />
                @endif
                @if($qrCode)
                    <img src="{{ $qrCode }}" alt="QR Code para pagamento PIX" class="mx-auto w-64 max-w-full rounded-box bg-white p-3" />
                @endif
                @if($payable)
                    <p class="text-sm">A confirmação do pagamento aparecerá automaticamente nesta tela.</p>
                    @if($copyPaste)
                        <div x-data="{ copied: false }" class="space-y-2">
                            <textarea readonly aria-label="PIX copia e cola" class="textarea textarea-bordered w-full" rows="3">{{ $copyPaste }}</textarea>
                            <button type="button" class="btn btn-outline w-full" @click="navigator.clipboard.writeText($el.previousElementSibling.value).then(() => copied = true)" x-text="copied ? 'PIX copiado' : 'Copiar PIX'"></button>
                        </div>
                    @endif
                    <div x-data="{ copied: false }" class="space-y-2">
                        <input type="text" readonly aria-label="Link de pagamento PIX" class="input input-bordered w-full" value="{{ $publicUrl }}" />
                        <button type="button" class="btn btn-outline w-full" @click="const url = $el.previousElementSibling.value; if (navigator.share) { navigator.share({ title: 'Pagamento PIX', url }).catch(() => {}); } else { navigator.clipboard.writeText(url).then(() => copied = true); }" x-text="copied ? 'Link copiado' : 'Compartilhar link'"></button>
                    </div>
                @endif
                <p wire:offline class="text-warning">Sem conexão — confirmação pendente de atualização.</p>
            </div>
        </x-operation.panel>
    @endif
</div>
