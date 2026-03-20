<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Ordem de Produção {{ $record->production_order_number }}</title>
    @include('pdf.partials.document-styles')
    <style>
        body {
            padding-bottom: 28px;
        }

        .signature-block {
            margin-top: 36px;
        }

        .signature-line {
            width: 260px;
            border-top: 1px solid #1f2937;
            padding-top: 4px;
        }

        .pdf-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: right;
            font-size: 10px;
            color: #6b7280;
        }
    </style>
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
        @if ($record->assignedOperator)
            <div class="line">Operador: {{ $record->assignedOperator?->name ?? '-' }}</div>
        @endif
        @if ($record->requisition)
            <div class="line">Requisição vinculada: {{ $record->requisition?->number ?? '-' }}</div>
        @endif
        @if ($record->started_at)
            <div class="line">Inicio: {{ $record->started_at?->format('d/m/Y H:i') ?? '-' }}</div>
        @endif
        @if ($record->completed_at)
            <div class="line">Conclusao: {{ $record->completed_at?->format('d/m/Y H:i') ?? '-' }}</div>
        @endif
        <div class="line">Observacoes: {{ $record->observations ?? 'Sem observações' }}</div>
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
    <div class="line">Total estimado: R$ {{ number_format($total, 2, ',', '.') }}</div>
    <div class="pdf-footer">Gerado em: {{ now()->format('d/m/Y H:i') }}</div>
</body>

</html>
