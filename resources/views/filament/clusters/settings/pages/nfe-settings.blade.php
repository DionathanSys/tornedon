<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <x-filament-actions::modals />

        <div style="margin-top: 1rem;">
            <x-filament::button type="submit" wire:loading.attr="disabled">
                Salvar Configurações
            </x-filament::button>
        </div>
    </form>

    <x-filament::section
        heading="Relatório de NFS-e emitidas (Prefeitura)"
        description="Consulta usando o método localiza() da IntegraNotas para exibir as notas emitidas no município."
        class="mt-6"
    >
        <form wire:submit="consultIssuedNfse" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <input type="number" min="1" wire:model.defer="consulta.numero_inicial" placeholder="Número inicial"
                    class="fi-input block w-full rounded-lg border-gray-300 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900" />

                <input type="number" min="1" wire:model.defer="consulta.numero_final" placeholder="Número final (opcional)"
                    class="fi-input block w-full rounded-lg border-gray-300 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900" />

                <input type="text" maxlength="5" wire:model.defer="consulta.serie" placeholder="Série (opcional)"
                    class="fi-input block w-full rounded-lg border-gray-300 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900" />

                <input type="number" min="1" wire:model.defer="consulta.pagina" placeholder="Página"
                    class="fi-input block w-full rounded-lg border-gray-300 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900" />
            </div>

            <div>
                <x-filament::button type="submit" wire:loading.attr="disabled" icon="heroicon-o-magnifying-glass">
                    Consultar NFS-e
                </x-filament::button>
            </div>
        </form>

        @if ($reportError)
            <div class="mt-4 rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm text-danger-700 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200">
                {{ $reportError }}
            </div>
        @endif

        @if (!empty($reportMeta))
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="text-xs text-gray-500">Código de retorno</div>
                    <div class="text-lg font-semibold">{{ $reportMeta['codigo'] ?? '-' }}</div>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="text-xs text-gray-500">Total de notas</div>
                    <div class="text-lg font-semibold">{{ $reportMeta['total_encontrado'] ?? 0 }}</div>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="text-xs text-gray-500">Valor total</div>
                    <div class="text-lg font-semibold">R$ {{ number_format((float) ($reportMeta['valor_total'] ?? 0), 2, ',', '.') }}</div>
                </div>
            </div>

            <div class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                <strong>Mensagem:</strong> {{ $reportMeta['mensagem'] ?? '-' }}
            </div>
        @endif

        @if (!empty($reportRows))
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">Número</th>
                            <th class="px-3 py-2 text-left font-medium">Série</th>
                            <th class="px-3 py-2 text-left font-medium">Emissão</th>
                            <th class="px-3 py-2 text-left font-medium">Tomador</th>
                            <th class="px-3 py-2 text-left font-medium">Valor</th>
                            <th class="px-3 py-2 text-left font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($reportRows as $row)
                            <tr>
                                <td class="px-3 py-2">{{ $row['numero'] ?? $row['numero_nfse'] ?? '-' }}</td>
                                <td class="px-3 py-2">{{ $row['serie'] ?? '-' }}</td>
                                <td class="px-3 py-2">{{ $row['data_emissao'] ?? $row['emissao'] ?? '-' }}</td>
                                <td class="px-3 py-2">{{ $row['tomador_razao_social'] ?? $row['tomador_nome'] ?? $row['tomador'] ?? '-' }}</td>
                                <td class="px-3 py-2">R$ {{ number_format((float) ($row['valor'] ?? $row['valor_servicos'] ?? 0), 2, ',', '.') }}</td>
                                <td class="px-3 py-2">{{ $row['status'] ?? $row['situacao'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
