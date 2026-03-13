<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Requisição {{ $record->number }}</title>
    @include('pdf.partials.document-styles')
    <style>
        body { padding-bottom: 28px; }
        .signature-block { margin-top: 36px; }
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
        <h1>Requisição #{{ $record->number }}</h1>
        <div class="line muted">Empresa: {{ $record->company?->name ?? '-' }}</div>
        <div class="line">Cliente: {{ $record->customer?->name ?? '-' }}</div>
        <div class="line">Data da Venda: {{ $record->sale_date?->format('d/m/Y') ?? '-' }}</div>
        <div class="line">Status: {{ $record->status?->description() ?? '-' }}</div>
    </div>

    <div class="box">
        <div class="line">OS vinculada: {{ $record->serviceOrder?->number ?? '-' }}</div>
        <div class="line">Equipamento: {{ $record->equipment?->name ?? '-' }}</div>
        <div class="line">Vendedor: {{ $record->salesperson?->name ?? '-' }}</div>
        <div class="line">Observações: {{ $record->observations ?? '-' }}</div>
    </div>

    <h2>Itens da Requisição</h2>
    <table class="grid">
        <thead>
            <tr>
                <th>Produto</th>
                <th>Unidade</th>
                <th>Qtd</th>
                <th>Valor Unit.</th>
                <th>Desconto</th>
                <th>Total</th>
                <th>Obs</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($record->items as $item)
                <tr>
                    <td>{{ $item->product?->name ?? '-' }}</td>
                    <td>{{ $item->unit_of_measure ?? '-' }}</td>
                    <td>{{ number_format((float) $item->quantity, 3, ',', '.') }}</td>
                    <td>R$ {{ number_format((float) $item->unit_price, 2, ',', '.') }}</td>
                    <td>R$ {{ number_format((float) $item->discount_amount, 2, ',', '.') }}</td>
                    <td>R$ {{ number_format((float) $item->total_amount, 2, ',', '.') }}</td>
                    <td>{{ $item->observations ?? '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-right">Sem itens.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>Resumo</h2>
    <div class="line">Desconto total: R$ {{ number_format((float) $record->discount_amount, 2, ',', '.') }}</div>
    <div class="line">Valor total: R$ {{ number_format((float) $record->total_amount, 2, ',', '.') }}</div>

    <div class="signature-block">
        <div class="signature-line">Assinatura do Cliente</div>
    </div>

    <div class="pdf-footer">Gerado em: {{ now()->format('d/m/Y H:i') }}</div>
</body>
</html>
