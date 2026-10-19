<ul class="space-y-2">
    @foreach ($getState() ?? [] as $entry)
        <li>
            <strong>{{ $entry['name'] }}</strong> —
            @if ($entry['status'] === 'duplicate')
                Duplicado.
                <a class="underline" href="{{ $resource::getUrl('view', ['record' => $entry['original_import_id']]) }}">Abrir lote original #{{ $entry['original_import_id'] }}</a>
                (arquivo #{{ $entry['file_id'] }}).
            @elseif ($entry['status'] === 'accepted')
                Aceito (arquivo #{{ $entry['file_id'] }}).
            @else
                Rejeitado: {{ $entry['error'] ?? 'Falha no envio.' }}
            @endif
        </li>
    @endforeach
</ul>
