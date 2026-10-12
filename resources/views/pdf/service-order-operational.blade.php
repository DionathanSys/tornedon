<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Ficha operacional — OS {{ $record->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        h2 { font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        td { border: 1px solid #ccc; padding: 7px; vertical-align: top; width: 50%; }
        .label { font-weight: bold; margin-bottom: 4px; }
        .text { white-space: pre-wrap; overflow-wrap: break-word; }
        .defect { margin-bottom: 14px; }
        .blank { height: 45px; border-bottom: 1px solid #ddd; }
        .muted { color: #555; }
    </style>
</head>
<body>
    <h1>Ficha operacional — OS #{{ $record->number }}</h1>
    <p class="muted">{{ $record->company?->name }} · {{ $record->order_date?->format('d/m/Y') }} · {{ $record->status?->description() }}</p>
    <table>
        <tr>
            <td><div class="label">Cliente</div>{{ $record->customer?->name ?? '—' }}</td>
            <td><div class="label">Veículo / equipamento</div>{{ $record->equipment?->name ?? '—' }} · {{ $record->equipment?->identifier ?? '—' }}<br>{{ $record->equipment?->mark }} {{ $record->equipment?->model }}</td>
        </tr>
        @if($record->equipment?->isVehicle())
            <tr><td colspan="2"><div class="label">Quilometragem na entrada</div>{{ $record->entry_mileage !== null ? number_format($record->entry_mileage, 0, ',', '.').' km' : '—' }}</td></tr>
        @endif
        <tr>
            <td><div class="label">Técnico responsável</div>{{ $record->technician?->name ?? 'Não atribuído' }}</td>
            <td><div class="label">Itens recebidos</div><div class="text">{{ $record->items_received ?: '—' }}</div></td>
        </tr>
    </table>
    <h2>Defeitos relatados</h2>
    @forelse($record->defects as $defect)
        <div class="defect">
            <p class="label">Defeito {{ $loop->iteration }}</p>
            <table>
                <tr><td colspan="2"><div class="label">Defeito citado</div><div class="text">{{ $defect->report }}</div></td></tr>
            </table>
        </div>
    @empty
        <p>Nenhum defeito registrado na lista.</p>
    @endforelse
    @foreach(['customer_observations' => 'Observações do cliente', 'general_observations' => 'Observações gerais', 'technician_observations' => 'Observações do técnico', 'solution' => 'Solução geral aplicada'] as $field => $label)
        @if(filled($record->$field))
            <p class="label">{{ $label }}</p><div class="text">{{ $record->$field }}</div>
        @endif
    @endforeach
    <h2>Anotações / conferência da equipe</h2>
    <div class="blank"></div><div class="blank"></div>
</body>
</html>
