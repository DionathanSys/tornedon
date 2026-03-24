<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Fatura {{ $record->invoice_number }}</title>
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
            position: relative;
        }
        .page-header-title {
            font-size: 18px;
            font-weight: bold;
        }
        .page-header-body {
            padding: 10px 12px;
        }
        .company-logo-wrap {
            position: absolute;
            top: 6px;
            right: 12px;
            background: #ffffff;
            border-radius: 4px;
            padding: 2px 6px;
            line-height: 0;
        }
        .company-logo {
            max-height: 44px;
            max-width: 130px;
            display: block;
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
        .grid th {
            background: #e8eef4;
            text-align: center;
        }
        .grid td:nth-child(1),
        .grid td:nth-child(2) {
            text-align: center;
            white-space: nowrap;
        }
        .grid td:nth-child(4) {
            text-align: right;
            white-space: nowrap;
            font-weight: bold;
        }
        .item-line + .item-line {
            margin-top: 2px;
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
        $formatDate = fn ($date) => $date?->format('d/m/Y') ?? '-';
        $formatMoney = fn ($value) => 'R$ ' . number_format((float) $value, 2, ',', '.');
        $formatQuantity = fn ($value) => number_format((float) $value, 3, ',', '.');
        $companyLogo = null;

        $headerLines = [
            ['label' => 'Empresa', 'value' => $record->company?->name ?? '-', 'class' => 'muted'],
            ['label' => 'Cliente', 'value' => $record->customer?->name ?? '-'],
            ['label' => 'Data da Fatura', 'value' => $formatDate($record->invoice_date)],
            ['label' => 'Status', 'value' => $record->status?->description() ?? '-'],
        ];

        $relatedLines = collect([
            ['label' => 'Ordens de Serviço', 'value' => $record->serviceOrders->count()],
            ['label' => 'Requisições', 'value' => $record->requisitions->count()],
            ['label' => 'Ordens de Produção', 'value' => $record->productionOrders->count()],
        ])->filter(function ($field) {
            $value = $field['value'] ?? null;

            if (is_numeric($value)) {
                return (int) $value > 0;
            }

            return filled($value);
        });

        $summaryLines = collect([
            $record->discount_amount > 0
                ? ['label' => 'Desconto total', 'value' => $formatMoney($record->discount_amount)]
                : null,
            ['label' => 'Total geral da fatura', 'value' => $formatMoney($record->total_amount)],
        ])->filter();

        if (filled($record->company?->logo_path) && \Illuminate\Support\Facades\Storage::disk('public')->exists($record->company->logo_path)) {
            $logoDisk = \Illuminate\Support\Facades\Storage::disk('public');
            $logoMime = $logoDisk->mimeType($record->company->logo_path) ?: 'image/png';
            $companyLogo = 'data:' . $logoMime . ';base64,' . base64_encode((string) $logoDisk->get($record->company->logo_path));
        }
    @endphp

    <div class="page-header">
        <div class="page-header-bar">
            <div class="page-header-title">Fatura #{{ $record->invoice_number }}</div>
            @if (filled($companyLogo))
                <div class="company-logo-wrap">
                    <img src="{{ $companyLogo }}" alt="Logo {{ $record->company->name }}" class="company-logo">
                </div>
            @endif
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
                    <!-- @foreach ($relatedLines as $line)
                        <tr>
                            <td class="meta-label">{{ $line['label'] }}</td>
                            <td>{{ $line['value'] }}</td>
                        </tr>
                    @endforeach -->
                </tbody>
            </table>
        </div>
    </div>

    @if ($record->serviceOrders->isNotEmpty())
        <div class="section-title">Ordens de Serviço Vinculadas - {{$record->serviceOrders->count()}}</div>
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
                @foreach ($record->serviceOrders as $serviceOrder)
                    <tr>
                        <td>#{{ $serviceOrder->number }}</td>
                        <td>{{ $serviceOrder->status?->description() ?? '-' }}</td>
                        <td>
                            @forelse ($serviceOrder->items as $item)
                                <div class="item-line">
                                    {{ $item->service?->name ?? '-' }} ({{ $formatQuantity($item->quantity) }})
                                </div>
                            @empty
                                -
                            @endforelse
                        </td>
                        <td>{{ $formatMoney($serviceOrder->total_amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($record->requisitions->isNotEmpty())
        <div class="section-title">Requisicoes Vinculadas - {{$record->requisitions->count()}}</div>
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
                @foreach ($record->requisitions as $requisition)
                    <tr>
                        <td>#{{ $requisition->number }}</td>
                        <td>{{ $requisition->status?->description() ?? '-' }}</td>
                        <td>
                            @forelse ($requisition->items as $item)
                                <div class="item-line">
                                    {{ $item->product?->name ?? '-' }} ({{ $formatQuantity($item->quantity) }})
                                </div>
                            @empty
                                -
                            @endforelse
                        </td>
                        <td>{{ $formatMoney($requisition->total_amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($record->productionOrders->isNotEmpty())
        <div class="section-title">Ordens de Producao Vinculadas - $record->productionOrders->count()}}</div>
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
                @foreach ($record->productionOrders as $productionOrder)
                    @php
                        $lineTotal = $productionOrder->items->sum(function ($item) {
                            $qty = (float) ($item->quantity_approved ?: $item->quantity_produced ?: $item->quantity);
                            $unit = (float) ($item->quoteItem?->unit_price ?? 0);

                            return $qty * $unit;
                        });
                    @endphp
                    <tr>
                        <td>#{{ $productionOrder->production_order_number }}</td>
                        <td>{{ $productionOrder->status?->description() ?? '-' }}</td>
                        <td>
                            @forelse ($productionOrder->items as $item)
                                <div class="item-line">
                                    {{ $item->product?->name ?? '-' }} ({{ $formatQuantity($item->quantity) }})
                                </div>
                            @empty
                                -
                            @endforelse
                        </td>
                        <td>{{ $formatMoney($lineTotal) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="section-title">Resumo</div>
    <table class="summary-table">
        <tbody>
            @foreach ($summaryLines as $line)
                <tr class="{{ $loop->last ? 'summary-total' : '' }}">
                    <td>{{ $line['label'] }}</td>
                    <td>{{ $line['value'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="pdf-footer">Gerado em: {{ now()->format('d/m/Y H:i') }}</div>
</body>

</html>
