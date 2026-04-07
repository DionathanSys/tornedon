<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>{{ $pdfData['title'] }}</title>
    @include('pdf.partials.document-styles')
    <style>
        @page {
            margin: 24px 24px 34px 24px;
        }

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
            padding: 8px 10px;
        }

        .header-layout {
            width: 100%;
            border-collapse: collapse;
        }

        .header-layout td {
            vertical-align: top;
            padding: 0;
        }

        .header-meta-cell {
            padding-right: 14px;
        }

        .header-logo-cell {
            width: 100px;
        }

        .company-logo-wrap {
            width: 100px;
            height: 96px;
            border: 1px solid #d1d5db;
            background: #ffffff;
            text-align: center;
            padding: 2px;
            overflow: hidden;
        }

        .company-logo {
            width: 96px;
            height: 88px;
            display: block;
            margin: 0 auto;
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

        .meta-notes-value {
            padding: 0;
        }

        .meta-notes-content {
            min-height: 12px;
            padding: 10px;
            white-space: pre-line;
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
    <div class="page-header">
        <div class="page-header-bar">
            <div class="page-header-title">{{ $pdfData['title'] }} - {{ $pdfData['status'] }}</div>
        </div>
        <div class="page-header-body">
            <table class="header-layout">
                <tr>
                    <td class="header-meta-cell">
                        <table class="meta-grid">
                            <tbody>
                                @foreach ($pdfData['header_lines'] as $line)
                                    <tr>
                                        <td class="meta-label">{{ $line['label'] }}</td>
                                        <td class="{{ $line['class'] ?? '' }}">{{ $line['value'] }}</td>
                                    </tr>
                                @endforeach
                                <tr>
                                    <td class="meta-label">Data da Ordem</td>
                                    <td>{{ $pdfData['order_date'] }}</td>
                                </tr>
                                <tr>
                                    <td class="meta-label">Data Conclusao</td>
                                    <td>{{ $pdfData['completion_date'] }}</td>
                                </tr>
                                @foreach ($pdfData['responsibles'] as $field)
                                    <tr>
                                        <td class="meta-label">{{ $field['label'] }}</td>
                                        <td>{{ $field['value'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </td>
                    <td class="header-logo-cell">
                        <div class="company-logo-wrap">
                            @if (filled($pdfData['company_logo']))
                                <img src="{{ $pdfData['company_logo'] }}" alt="Logo {{ $pdfData['company_name'] }}" class="company-logo">
                            @endif
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="section-title">Itens</div>
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
            @forelse ($pdfData['items'] as $item)
            <tr>
                <td>{{ $item['service'] }}</td>
                <td>{{ $item['quantity'] }}</td>
                <td>{{ $item['unit_price'] }}</td>
                <td>{{ $item['discount_amount'] }}</td>
                <td>{{ $item['total_amount'] }}</td>
                <td>{{ $item['observations'] }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="text-right">Sem itens.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    @if (filled($pdfData['customer_observations']) || filled($pdfData['solution']) || filled($pdfData['technician_observations']))
    <div class="section-title">Observações</div>
    <table class="meta-grid">
        <tbody>
            <tr>
                @if (filled($pdfData['customer_observations']))
                <td class="meta-inline-label">Observações</td>
                <td colspan="3" class="meta-inline-value">
                    {{ $pdfData['customer_observations'] }}
                </td>
                @endif
            </tr>
            <tr>
                @if (filled($pdfData['solution']))
                <td class="meta-inline-label">Solução aplicada</td>
                <td colspan="3" class="meta-inline-value">
                    {{ $pdfData['solution'] }}
                </td>
                @endif
            </tr>
            <tr>
                @if (filled($pdfData['technician_observations']))
                <td class="meta-inline-label">Observações Técnico</td>
                <td colspan="3" class="meta-inline-value">
                    {{ $pdfData['technician_observations'] }}
                </td>
                @endif
            </tr>
        </tbody>
    </table>
    @endif

    @if (filled($pdfData['additional_info_text']))
    <div class="section-title">Informacoes Adicionais</div>
    <div class="notes-box">{{ $pdfData['additional_info_text'] }}</div>
    @endif

    <div class="section-title">Resumo</div>
    <table class="summary-table">
        <tbody>
            @foreach ($pdfData['summary_lines'] as $line)
                <tr class="{{ $loop->last ? 'summary-total' : '' }}">
                    <td>{{ $line['label'] }}</td>
                    <td>{{ $line['value'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="signature-block">
        @if (filled($pdfData['customer_signature']))
        <img src="{{ $pdfData['customer_signature'] }}" alt="Assinatura do cliente" class="signature-image">
        <div class="signature-line">Assinatura do Cliente</div>
        @if (filled($pdfData['customer_signed_at']))
        <div class="signature-date">Assinado em {{ $pdfData['customer_signed_at'] }}</div>
        @endif
        @else
        <div class="signature-line">Assinatura do Cliente</div>
        @endif
    </div>

    <div class="pdf-footer">Gerado em: {{ $pdfData['generated_at'] }}</div>
</body>

</html>
