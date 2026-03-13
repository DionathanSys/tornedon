<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Ordem de Serviço {{ $record->number }}</title>
    @include('pdf.partials.document-styles')
</head>

<body>
    <div class="head">
        <h1>Ordem de Serviço #{{ $record->number }}</h1>
        <div class="line muted">Empresa: {{ $record->company?->name ?? '-' }}</div>
        <div class="line">Cliente: {{ $record->customer?->name ?? '-' }}</div>
        <div class="line">Data da Ordem: {{ $record->order_date?->format('d/m/Y') ?? '-' }}</div>
        <div class="line">Data Finalização: {{ $record->completion_date?->format('d/m/Y') ?? '-' }}</div>
        <div class="line">Status: {{ $record->status?->description() ?? '-' }}</div>
    </div>

    @if($record->equipment)
        <div class="line">Equipamento: {{ $record->equipment?->name ?? '-' }}</div>
    @endif
    @if($record->technician)
        <div class="line">Tecnico: {{ $record->technician?->name ?? '-' }}</div>
    @endif
    @if($record->supervisor)
        <div class="line">Supervisor: {{ $record->supervisor?->name ?? '-' }}</div>
    @endif
    @if($record->salesperson)
        <div class="line">Vendedor: {{ $record->salesperson?->name ?? '-' }}</div>
    @endif

    <h2>Itens da Ordem de Serviço</h2>
    <table class="grid">
        <thead>
            <tr>
                <th>Serviço</th>
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
                <td>{{ number_format((float) $item->quantity, 3, ',', '.') }}</td>
                <td>R$ {{ number_format((float) $item->unit_price, 2, ',', '.') }}</td>
                <td>R$ {{ number_format((float) $item->discount_amount, 2, ',', '.') }}</td>
                <td>R$ {{ number_format((float) $item->total_amount, 2, ',', '.') }}</td>
                <td>{{ $item->observations ?? '-' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="text-right">Sem itens.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <h2>Observações</h2>
        <div class="line">{{$record->customer_observations ?? 'Sem observações'}}</div>

    <h2>Resumo</h2>
    @if($record->discount_amount > 0)
        <div class="line">Desconto total: R$ {{ number_format((float) $record->discount_amount, 2, ',', '.') }}</div>
    @endif
    <div class="line">Valor total: R$ {{ number_format((float) $record->total_amount, 2, ',', '.') }}</div>
</body>

</html>