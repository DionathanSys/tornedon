<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Ordem de Serviço {{ $record->number }}</title>
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
            display: inline-block;
            vertical-align: middle;
        }
        .page-header-status {
            display: inline-block;
            vertical-align: middle;
            margin-left: 10px;
            padding: 4px 10px;
            border: 1px solid rgba(255, 255, 255, 0.45);
            border-radius: 999px;
            font-size: 11px;
            font-weight: bold;
        }
        .page-header-body {
            position: relative;
            padding: 8px 10px;
        }
        .company-logo-wrap {
            position: absolute;
            top: 8px;
            right: 10px;
            padding: 0;
            text-align: right;
            line-height: 0;
        }
        .company-logo {
            max-height: 100px;
            max-width: 130px;
            display: block;
        }
        .header-meta-wrap.has-logo {
            padding-right: 98px;
            min-height: 63px;
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
        .meta-inline-label {
            width: 23%;
            background: #f8fafc;
            color: #17385b;
            font-weight: bold;
        }
        .meta-inline-value {
            width: 29%;
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
        .grid td:nth-child(2),
        .grid td:nth-child(3),
        .grid td:nth-child(4),
        .grid td:nth-child(5) {
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
        .signature-block {
            margin-top: 32px;
            text-align: center;
        }
        .signature-line {
            width: 260px;
            border-top: 1px solid #1f2937;
            padding-top: 16px;
            margin: 0 auto;
        }
        .signature-image {
            display: block;
            max-width: 260px;
            max-height: 110px;
            margin: 0 auto 8px auto;
        }
        .signature-date {
            margin-top: 6px;
            font-size: 11px;
            color: #6b7280;
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
        $additionalInfoLabels = [
            'accessories' => 'Acessorios entregues',
            'avaria' => 'Avaria identificada',
            'budget' => 'Orcamento alinhado',
            'cleaning' => 'Limpeza executada',
            'guidance' => 'Orientacoes ao cliente',
            'parts' => 'Pecas substituidas',
            'pending' => 'Pendencia encontrada',
            'test' => 'Teste realizado',
            'warranty' => 'Garantia informada',
            'other' => 'Outro',
        ];

        $headerLines = [
            ['label' => 'Empresa', 'value' => $record->company?->name ?? '-', 'class' => 'muted'],
            ['label' => 'Cliente', 'value' => $record->customer?->name ?? '-'],
        ];

        $responsibles = collect([
            ['label' => 'Equipamento', 'value' => $record->equipment?->identifier],
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

        $additionalInfo = collect($record->additional_info ?? []);
        $additionalInfoText = null;

        if ($additionalInfo->isNotEmpty()) {
            $first = $additionalInfo->first();

            if (is_array($first) && array_key_exists('type', $first)) {
                $additionalInfo = $additionalInfo
                    ->map(function ($item) use ($additionalInfoLabels) {
                        if (! is_array($item)) {
                            return null;
                        }

                        $type = $item['type'] ?? null;
                        $label = filled($type)
                            ? ($additionalInfoLabels[$type] ?? (string) $type)
                            : 'Outro';
                        $observation = $item['observation'] ?? null;

                        return [
                            'label' => $label,
                            'value' => filled($observation) ? $observation : null,
                        ];
                    })
                    ->filter();
            } else {
                $additionalInfo = $additionalInfo
                    ->map(function ($value, $key) use ($additionalInfoLabels) {
                        $label = $additionalInfoLabels[(string) $key] ?? (string) $key;

                        return [
                            'label' => $label,
                            'value' => is_scalar($value) ? (string) $value : null,
                        ];
                    })
                    ->filter();
            }
        }

        $additionalInfoText = $additionalInfo
            ->map(function ($info) {
                if (! is_array($info)) {
                    return null;
                }

                $label = $info['label'] ?? null;
                $value = $info['value'] ?? null;

                if (filled($label) && filled($value)) {
                    return "{$label}: {$value}";
                }

                return $label ?: $value;
            })
            ->filter()
            ->implode(' | ');

        if (filled($record->company?->logo_path) && \Illuminate\Support\Facades\Storage::disk('public')->exists($record->company->logo_path)) {
            $logoDisk = \Illuminate\Support\Facades\Storage::disk('public');
            $logoMime = $logoDisk->mimeType($record->company->logo_path) ?: 'image/png';
            $companyLogo = 'data:' . $logoMime . ';base64,' . base64_encode((string) $logoDisk->get($record->company->logo_path));
        }
    @endphp

    <div class="page-header">
        <div class="page-header-bar">
            <div class="page-header-title">Ordem de Serviço #{{ $record->number }}</div>
            <div class="page-header-status">{{ $record->status?->description() ?? '-' }}</div>
        </div>
        <div class="page-header-body">
            @if (filled($companyLogo))
                <div class="company-logo-wrap">
                    <img src="{{ $companyLogo }}" alt="Logo {{ $record->company->name }}" class="company-logo">
                </div>
            @endif
            <div class="header-meta-wrap{{ filled($companyLogo) ? ' has-logo' : '' }}">
                <table class="meta-grid">
                    <tbody>
                        @foreach ($headerLines as $line)
                            <tr>
                                <td class="meta-label">{{ $line['label'] }}</td>
                                <td colspan="3" class="{{ $line['class'] ?? '' }}">{{ $line['value'] }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <td class="meta-inline-label">Data da Ordem</td>
                            <td class="meta-inline-value">{{ $formatDate($record->order_date) }}</td>
                            <td class="meta-inline-label">Data Conclusão</td>
                            <td class="meta-inline-value">{{ $formatDate($record->completion_date) }}</td>
                        </tr>
                        @foreach ($responsibles as $field)
                            <tr>
                                <td class="meta-label">{{ $field['label'] }}</td>
                                <td colspan="3">{{ $field['value'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="section-title">Itens da Ordem de Serviço</div>
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

    <div class="section-title">Observações</div>
    <div class="notes-box">{{ $record->customer_observations ?? 'Sem observações' }}</div>

    @if (filled($additionalInfoText))
        <div class="section-title">Informacoes Adicionais</div>
        <div class="notes-box">{{ $additionalInfoText }}</div>
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

    <div class="signature-block">
        @if (filled($record->customer_signature))
            <img src="{{ $record->customer_signature }}" alt="Assinatura do cliente" class="signature-image">
            <div class="signature-line">Assinatura do Cliente</div>
            @if ($record->customer_signed_at)
                <div class="signature-date">Assinado em {{ $record->customer_signed_at->format('d/m/Y H:i') }}</div>
            @endif
        @else
            <div class="signature-line">Assinatura do Cliente</div>
        @endif
    </div>

    <div class="pdf-footer">Gerado em: {{ now()->format('d/m/Y H:i') }}</div>
</body>

</html>
