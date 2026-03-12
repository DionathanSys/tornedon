<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Fatura {{ $record->invoice_number }}</title>
    @include('pdf.partials.document-styles')
</head>
<body>
    <div class="head">
        <h1>Fatura #{{ $record->invoice_number }}</h1>
        <div class="line muted">Empresa: {{ $record->company?->name ?? '-' }}</div>
        <div class="line">Cliente: {{ $record->customer?->name ?? '-' }}</div>
        <div class="line">Data da Fatura: {{ $record->invoice_date?->format('d/m/Y') ?? '-' }}</div>
        <div class="line">Status: {{ $record->status?->description() ?? '-' }}</div>
    </div>

    <h2>Ordens de Servico Vinculadas</h2>
    <table class="grid">
        <thead>
            <tr>
                <th>OS</th>
                <th>Status</th>
                <th>Itens</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($record->serviceOrders as $serviceOrder)
                <tr>
                    <td>#{{ $serviceOrder->number }}</td>
                    <td>{{ $serviceOrder->status?->description() ?? '-' }}</td>
                    <td>
                        @forelse ($serviceOrder->items as $item)
                            {{ $item->service?->name ?? '-' }} ({{ number_format((float) $item->quantity, 3, ',', '.') }})<br>
                        @empty
                            -
                        @endforelse
                    </td>
                    <td>R$ {{ number_format((float) $serviceOrder->total_amount, 2, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-right">Sem ordens de servico vinculadas.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>Requisicoes Vinculadas</h2>
    <table class="grid">
        <thead>
            <tr>
                <th>Requisicao</th>
                <th>Status</th>
                <th>Itens</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($record->requisitions as $requisition)
                <tr>
                    <td>#{{ $requisition->number }}</td>
                    <td>{{ $requisition->status?->description() ?? '-' }}</td>
                    <td>
                        @forelse ($requisition->items as $item)
                            {{ $item->product?->name ?? '-' }} ({{ number_format((float) $item->quantity, 3, ',', '.') }})<br>
                        @empty
                            -
                        @endforelse
                    </td>
                    <td>R$ {{ number_format((float) $requisition->total_amount, 2, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-right">Sem requisicoes vinculadas.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>Ordens de Producao Vinculadas</h2>
    <table class="grid">
        <thead>
            <tr>
                <th>OP</th>
                <th>Status</th>
                <th>Itens</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @php($productionTotal = 0)
            @forelse ($record->productionOrders as $productionOrder)
                @php
                    $lineTotal = $productionOrder->items->sum(function ($item) {
                        $qty = (float) ($item->quantity_approved ?: $item->quantity_produced ?: $item->quantity);
                        $unit = (float) ($item->quoteItem?->unit_price ?? 0);

                        return $qty * $unit;
                    });
                    $productionTotal += $lineTotal;
                @endphp
                <tr>
                    <td>#{{ $productionOrder->production_order_number }}</td>
                    <td>{{ $productionOrder->status?->description() ?? '-' }}</td>
                    <td>
                        @forelse ($productionOrder->items as $item)
                            {{ $item->product?->name ?? '-' }} ({{ number_format((float) $item->quantity, 3, ',', '.') }})<br>
                        @empty
                            -
                        @endforelse
                    </td>
                    <td>R$ {{ number_format((float) $lineTotal, 2, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-right">Sem ordens de producao vinculadas.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>Resumo</h2>
    <div class="line">Desconto total: R$ {{ number_format((float) $record->discount_amount, 2, ',', '.') }}</div>
    <div class="line">Total geral da fatura: R$ {{ number_format((float) $record->total_amount, 2, ',', '.') }}</div>
</body>
</html>
