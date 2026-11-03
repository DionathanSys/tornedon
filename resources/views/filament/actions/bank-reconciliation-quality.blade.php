@php
    $percentage = fn ($value) => $value === null ? '—' : number_format($value, 1, ',', '.') . '%';
    $cards = [
        ['Cobertura das linhas consultadas', $percentage($metrics['coverage_percent']), $metrics['covered_lines'] . ' de ' . $metrics['evaluated_lines'] . ' linhas; última consulta concluída de cada linha.'],
        ['Concordância da primeira opção', $percentage($metrics['top1_percent']), $metrics['reviewed_single_decisions'] . ' decisões com um registro e consulta vinculada.'],
        ['Correspondência nas três primeiras', $percentage($metrics['top3_percent']), 'Mesma base de decisões; conciliações desfeitas não contam como acerto.'],
        ['Rejeições explícitas', $percentage($metrics['rejection_percent']), $metrics['explicit_rejections'] . ' consultas avaliadas como sem correspondência pelo usuário.'],
        ['Desfazimentos', $percentage($metrics['reversal_percent']), $metrics['reversed_decisions'] . ' de ' . $metrics['confirmed_decisions'] . ' decisões confirmadas.'],
        ['Tempo médio da busca', $metrics['average_duration_ms'] === null ? '—' : number_format($metrics['average_duration_ms'], 0, ',', '.') . ' ms', 'Tempo no servidor, incluindo sugestões individuais e composições.'],
    ];
@endphp
<div class="space-y-6">
    <p class="text-sm text-gray-600 dark:text-gray-400">Últimos 90 dias, na empresa atual. O acompanhamento começa com a ativação desta versão. As escolhas confirmadas são a referência de revisão; estes indicadores não são probabilidades de acerto.</p>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
        @foreach ($cards as [$label, $value, $description])
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <h3 class="text-sm font-medium">{{ $label }}</h3>
                <p class="mt-2 text-2xl font-semibold">{{ $value }}</p>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $description }}</p>
            </div>
        @endforeach
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-400">{{ $metrics['attempts'] }} consultas registradas; {{ $metrics['incomplete_attempts'] }} incompletas. {{ $metrics['bundle_matches'] }} decisões correspondem a propostas avançadas. {{ $metrics['outside_suggestions'] }} escolhas ficaram fora das opções consultadas. {{ $metrics['without_evaluation'] }} decisões não têm consulta vinculada e não entram nos indicadores da primeira opção e das três primeiras.</p>
    <div class="overflow-x-auto">
        <h3 class="mb-3 text-sm font-semibold">Decisões recentes</h3>
        <table class="w-full text-left text-sm">
            <thead><tr class="border-b border-gray-200 dark:border-gray-700"><th class="p-2">Confirmação</th><th class="p-2">Parte / histórico bancário</th><th class="p-2">Escolha confirmada</th><th class="p-2">Resultado</th></tr></thead>
            <tbody>
                @forelse ($metrics['recent'] as $decision)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="p-2">{{ $decision->confirmed_at->format('d/m/Y H:i') }}</td>
                        <td class="p-2">{{ data_get($decision->bank_snapshot, '0.name') ?: data_get($decision->bank_snapshot, '0.description') }}</td>
                        <td class="p-2">{{ collect($decision->selected_targets)->map(fn ($target) => match ($target['type']) { 'payable' => 'Pagar', 'receivable' => 'Receber', default => 'Movimento' } . ' #' . $target['id'])->implode('; ') }}</td>
                        <td class="p-2">{{ $decision->reversed_at ? 'Desfeita' : 'Confirmada' }}{{ $decision->individual_rank ? ' · opção ' . $decision->individual_rank : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="p-4 text-gray-500">Ainda não há decisões registradas para este período.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
