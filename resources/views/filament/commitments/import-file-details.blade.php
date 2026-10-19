<div class="space-y-4">
    <dl class="grid grid-cols-2 gap-3">
        <div><dt>Arquivo</dt><dd>{{ $record->original_name }}</dd></div>
        <div><dt>Situação</dt><dd>{{ $record->status->description() }}</dd></div>
        <div><dt>Versão do leitor</dt><dd>{{ $record->parser_version ?? '—' }}</dd></div>
        <div><dt>Processado em</dt><dd>{{ $record->processed_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
    </dl>
    @if ($record->last_error)
        <p>{{ $record->last_error }}</p>
    @endif
    @foreach (data_get($record->extracted_data, 'issues', []) as $issue)
        <p class="text-amber-600 dark:text-amber-400">{{ $issue }}</p>
    @endforeach
    @if (data_get($record->extracted_data, 'number'))
        <p><strong>Empenho:</strong> {{ $record->extracted_data['number'] }}/{{ $record->extracted_data['fiscal_year'] }}</p>
        <p><strong>Patrimônio:</strong> {{ $record->extracted_data['patrimony'] ?? 'Não identificado' }}</p>
        <p>{{ $record->extracted_data['object'] }}</p>
        <ul class="list-disc pl-5">
            @foreach (data_get($record->extracted_data, 'items', []) as $item)
                <li>{{ $item['description'] }} — {{ $item['complement'] }} — R$ {{ number_format($item['amount_cents'] / 100, 2, ',', '.') }}</li>
            @endforeach
        </ul>
    @endif
    @if (data_get($record->extracted_data, 'lines'))
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr><th class="p-2">Empenho</th><th class="p-2">Pagamento</th><th class="p-2">Patrimônio</th><th class="p-2">Nota(s)</th><th class="p-2">Valor</th><th class="p-2">Páginas</th><th class="p-2">Pendências</th></tr></thead>
                <tbody>
                    @foreach ($record->extracted_data['lines'] as $line)
                        <tr class="border-t">
                            <td class="p-2">{{ $line['commitment_number'] }}/{{ $line['fiscal_year'] }}</td>
                            <td class="p-2">{{ \Carbon\Carbon::parse($line['payment_date'])->format('d/m/Y') }}</td>
                            <td class="p-2">{{ $line['patrimony'] ?? '—' }}</td>
                            <td class="p-2">{{ implode(', ', array_column($line['fiscal_documents'], 'number')) }}</td>
                            <td class="p-2 whitespace-nowrap">R$ {{ number_format($line['amount_cents'] / 100, 2, ',', '.') }}</td>
                            <td class="p-2">{{ $line['page_start'] }}–{{ $line['page_end'] }}</td>
                            <td class="p-2">{{ implode(' ', $line['issues']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if ($record->extracted_text)
        <details><summary>Texto original extraído</summary><pre class="mt-2 max-h-96 overflow-auto whitespace-pre-wrap text-xs">{{ $record->extracted_text }}</pre></details>
    @endif
</div>
