<x-operation.theme>
    <aside
        x-data="operationPwaInstall()"
        x-cloak
        x-show="available && !dismissed"
        class="operation-pwa-install rounded-box border border-base-300 bg-base-100 p-4 shadow-lg"
        aria-label="Instalação do aplicativo"
    >
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="font-semibold">Axion Gestão Operação</p>
                <p class="mt-1">Instale para acessar pelo ícone na tela inicial.</p>
            </div>
            <button type="button" class="btn btn-ghost btn-sm" @click="dismiss()" aria-label="Dispensar sugestão de instalação">✕</button>
        </div>
        <p x-show="instructions" class="mt-3" role="status">
            No iPhone ou iPad, abra no Safari, toque em Compartilhar e escolha
            “Adicionar à Tela de Início”. Ative “Abrir como App”, se disponível, e toque em Adicionar.
        </p>
        <button type="button" class="btn btn-primary btn-sm mt-3" @click="install()" :disabled="installing" x-show="!instructions">
            Instalar aplicativo
        </button>
    </aside>
</x-operation.theme>
