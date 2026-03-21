<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Ordem de Servico {{ $record->number }}</title>
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
    @php
        $formatDate = fn ($date) => $date?->format('d/m/Y') ?? '-';
        $formatMoney = fn ($value) => 'R$ ' . number_format((float) $value, 2, ',', '.');
        $formatQuantity = fn ($value) => number_format((float) $value, 3, ',', '.');

        $headerLines = [
            ['label' => 'Empresa', 'value' => $record->company?->name ?? '-', 'class' => 'muted'],
            ['label' => 'Cliente', 'value' => $record->customer?->name ?? '-'],
            ['label' => 'Data da Ordem', 'value' => $formatDate($record->order_date)],
            ['label' => 'Data Finalizacao', 'value' => $formatDate($record->completion_date)],
            ['label' => 'Status', 'value' => $record->status?->description() ?? '-'],
        ];

        $responsibles = collect([
            ['label' => 'Equipamento', 'value' => $record->equipment?->name],
            ['label' => 'Tecnico', 'value' => $record->technician?->name],
            ['label' => 'Supervisor', 'value' => $record->supervisor?->name],
            ['label' => 'Vendedor', 'value' => $record->salesperson?->name],
        ])->filter(fn ($field) => filled($field['value']));

        $summaryLines = collect([
            $record->discount_amount > 0
                ? ['label' => 'Desconto total', 'value' => $formatMoney($record->discount_amount)]
                : null,
            ['label' => 'Valor total', 'value' => $formatMoney($record->total_amount)],
        ])->filter();
    @endphp

    <div class="head">
        <h1>Ordem de Servico #{{ $record->number }}</h1>
        @foreach ($headerLines as $line)
            <div class="line {{ $line['class'] ?? '' }}">{{ $line['label'] }}: {{ $line['value'] }}</div>
        @endforeach
    </div>

    @if ($responsibles->isNotEmpty())
        @foreach ($responsibles as $field)
            <div class="line">{{ $field['label'] }}: {{ $field['value'] }}</div>
        @endforeach
    @endif

    <h2>Itens da Ordem de Servico</h2>
    <table class="grid">
        <thead>
            <tr>
                <th>Servico</th>
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
                    <td>{{ $item->service?->name ?? '-' }}</td>
                    <td>{{ $formatQuantity($item->quantity) }}</td>
                    <td>{{ $formatMoney($item->unit_price) }}</td>
                    <td>{{ $formatMoney($item->discount_amount) }}</td>
                    <td>{{ $formatMoney($item->total_amount) }}</td>
                    <td>{{ $item->observations ?? '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-right">Sem itens.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>Observacoes</h2>
    <div class="line">{{ $record->customer_observations ?? 'Sem observacoes' }}</div>

    <h2>Resumo</h2>
    @foreach ($summaryLines as $line)
        <div class="line">{{ $line['label'] }}: {{ $line['value'] }}</div>
    @endforeach

    <div class="signature-block">
        <div class="signature-line">Assinatura do Cliente</div>
    </div>

    <div class="pdf-footer">Gerado em: {{ now()->format('d/m/Y H:i') }}</div>
</body>

</html>
