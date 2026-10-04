<div class="space-y-4">
    <p class="text-sm text-gray-600 dark:text-gray-300">
        Este link ficará disponível até <strong>{{ $expiresAt->format('d/m/Y H:i') }}</strong>.
        A página também verifica o status atual da cobrança antes de exibir os dados de pagamento.
    </p>

    <div class="flex gap-2">
        <input
            id="pix-payment-link-{{ $charge->id }}"
            type="text"
            value="{{ $url }}"
            readonly
            onclick="this.select()"
            class="w-full rounded-lg border-gray-300 bg-gray-50 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800"
        >
        <button
            type="button"
            data-charge-id="{{ $charge->id }}"
            onclick="copyPixPaymentLink(this.dataset.chargeId, this)"
            class="rounded-lg bg-primary-600 px-3 text-sm font-semibold text-white hover:bg-primary-500"
        >
            Copiar
        </button>
    </div>

    <a
        href="{{ $url }}"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center rounded-lg bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-100"
    >
        Abrir página de pagamento
    </a>
</div>

<script>
    function copyPixPaymentLink(chargeId, button) {
        const field = document.getElementById(`pix-payment-link-${chargeId}`);

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(field.value).then(() => {
                button.textContent = 'Copiado';
            });
            return;
        }

        field.select();
        document.execCommand('copy');
        button.textContent = 'Copiado';
    }
</script>
