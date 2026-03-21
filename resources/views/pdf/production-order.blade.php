<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Ordem de Producao {{ $record->production_order_number }}</title>
    @include('pdf.partials.document-styles')
    <style>
        @page { margin: 24px 24px 34px 24px; }
        body {
            padding-bottom: 28px;
            color: #111827;
        }
        .page-header {
            border: 1px solid #cfd7df;
            margin-bottom: 14px;
        }
        .page-header-bar {
            background: #17385b;
            color: #ffffff;
            padding: 10px 12px;
        }
        .page-header-title {
            font-size: 18px;
            font-weight: bold;
        }
        .page-header-body {
            padding: 10px 12px;
        }
        .meta-grid,
        .summary-table {
            width: 100%;
            border-collapse: collapse;
        }
        .meta-grid td,
        .summary-table td {
            border: 1px solid #d1d5db;
            padding: 7px 8px;
            vertical-align: top;
        }
        .meta-label {
            width: 22%;
            background: #f8fafc;
            color: #17385b;
            font-weight: bold;
        }
        .section-title {
            margin: 18px 0 8px 0;
            padding: 6px 10px;
            background: #17385b;
            color: #ffffff;
            font-size: 13px;
            font-weight: bold;
        }
        .notes-box {
            border: 1px solid #d1d5db;
            padding: 10px;
            min-height: 56px;
        }
        .grid th {
            background: #e8eef4;
            text-align: center;
        }
        .grid td:nth-child(3),
        .grid td:nth-child(4),
        .grid td:nth-child(5),
        .grid td:nth-child(6),
        .grid td:nth-child(7) {
            text-align: center;
            white-space: nowrap;
        }
        .summary-table td:last-child {
            text-align: right;
            font-weight: bold;
        }
        .summary-total td {
            background: #e8eef4;
            font-size: 13px;
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
    @php
        $formatDateTime = fn ($date) => $date?->format('d/m/Y H:i') ?? '-';
        $formatMoney = fn ($value) => 'R$ ' . number_format((float) $value, 2, ',', '.');
        $formatQuantity = fn ($value) => number_format((float) $value, 3, ',', '.');

        $headerLines = [
            ['label' => 'Empresa', 'value' => $record->company?->name ?? '-', 'class' => 'muted'],
            ['label' => 'Cliente', 'value' => $record->customer?->name ?? '-'],
            ['label' => 'Status', 'value' => $record->status?->description() ?? '-'],
            ['label' => 'Prioridade', 'value' => $record->priority?->description() ?? '-'],
            ['label' => 'Operador', 'value' => $record->assignedOperator?->name ?? '-'],
            ['label' => 'Requisicao vinculada', 'value' => $record->requisition?->number ?? '-'],
            ['label' => 'Inicio', 'value' => $formatDateTime($record->started_at)],
            ['label' => 'Conclusao', 'value' => $formatDateTime($record->completed_at)],
        ];

        $total = 0;
    @endphp

    <div class="page-header">
        <div class="page-header-bar">
            <div class="page-header-title">Ordem de Producao #{{ $record->production_order_number }}</div>
        </div>
        <div class="page-header-body">
            <table class="meta-grid">
                <tbody>
                    @foreach ($headerLines as $line)
                        <tr>
                            <td class="meta-label">{{ $line['label'] }}</td>
                            <td class="{{ $line['class'] ?? '' }}">{{ $line['value'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="section-title">Itens da Ordem de Producao</div>
    <table class="grid">
        <thead>
            <tr>
                <th>Produto</th>
                <th>Descricao</th>
                <th>Qtd Prevista</th>
                <th>Qtd Produzida</th>
                <th>Qtd Aprovada</th>
                <th>Valor Unit.</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
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
                    <td>{{ $formatQuantity($item->quantity) }}</td>
                    <td>{{ $formatQuantity($item->quantity_produced) }}</td>
                    <td>{{ $formatQuantity($item->quantity_approved) }}</td>
                    <td>{{ $formatMoney($unit) }}</td>
                    <td>{{ $formatMoney($lineTotal) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-right">Sem itens.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="section-title">Observacoes</div>
    <div class="notes-box">{{ $record->observations ?? 'Sem observacoes' }}</div>

    <div class="section-title">Resumo</div>
    <table class="summary-table">
        <tbody>
            <tr class="summary-total">
                <td>Total estimado da OP</td>
                <td>{{ $formatMoney($total) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="pdf-footer">Gerado em: {{ now()->format('d/m/Y H:i') }}</div>
</body>
</html>
