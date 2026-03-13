<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Ordem de Produção {{ $record->production_order_number }}</title>
    @include('pdf.partials.document-styles')
</head>
<body>
    <div class="head">
        <h1>Ordem de Produção #{{ $record->production_order_number }}</h1>
        <div class="line muted">Empresa: {{ $record->company?->name ?? '-' }}</div>
        <div class="line">Cliente: {{ $record->customer?->name ?? '-' }}</div>
        <div class="line">Status: {{ $record->status?->description() ?? '-' }}</div>
        <div class="line">Prioridade: {{ $record->priority?->description() ?? '-' }}</div>
    </div>

    <div class="box">
        <div class="line">Operador: {{ $record->assignedOperator?->name ?? '-' }}</div>
        <div class="line">Requisição vinculada: {{ $record->requisition?->number ?? '-' }}</div>
        <div class="line">Inicio: {{ $record->started_at?->format('d/m/Y H:i') ?? '-' }}</div>
        <div class="line">Conclusao: {{ $record->completed_at?->format('d/m/Y H:i') ?? '-' }}</div>
        <div class="line">Observacoes: {{ $record->observations ?? '-' }}</div>
    </div>

    <h2>Itens da Ordem de Produção</h2>
    <table class="grid">
        <thead>
            <tr>
                <th>Produto</th>
                <th>Descrição</th>
                <th>Qtd Prevista</th>
                <th>Qtd Produzida</th>
                <th>Qtd Aprovada</th>
                <th>Valor Unit.</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @php
                $total = 0;
            @endphp
            @forelse ($record->items as $item)
                @php
                    $qty = (float) ($item->quantity_approved ?: $item->quantity_produced ?: $item->quantity);
                    $unit = (float) ($item->quoteItem?->unit_price ?? 0);
                    $lineTotal = $qty * $unit;
                    $total += $lineTotal;
                @endphp
                <tr>
                    <td>{{ $item->product?->name ?? '-' }}</td>
                    <td>{{ $item->description ?? '-' }}</td>
                    <td>{{ number_format((float) $item->quantity, 3, ',', '.') }}</td>
                    <td>{{ number_format((float) $item->quantity_produced, 3, ',', '.') }}</td>
                    <td>{{ number_format((float) $item->quantity_approved, 3, ',', '.') }}</td>
                    <td>R$ {{ number_format($unit, 2, ',', '.') }}</td>
                    <td>R$ {{ number_format($lineTotal, 2, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-right">Sem itens.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>Resumo</h2>
    <div class="line">Total estimado da OP: R$ {{ number_format($total, 2, ',', '.') }}</div>
</body>
</html>
