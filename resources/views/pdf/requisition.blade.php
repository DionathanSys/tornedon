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
            height: 16px;
            background: #f8fafc;
            color: #17385b;
            font-weight: bold;
        }

        .relation-section {
            margin: 18px 0 8px 0;
        }

        .relation-title {
            margin: 0 0 6px 0;
            padding-bottom: 4px;
            border-bottom: 1px solid #d1d5db;
            color: #17385b;
            font-size: 12px;
            font-weight: bold;
        }

        .notes-box {
            white-space: pre-line;
        }

        .relation-table,
        .summary-table {
            width: 100%;
            border-collapse: collapse;
        }

        .relation-table th,
        .relation-table td {
            padding: 6px 4px;
            vertical-align: top;
            border-bottom: 1px solid #e5e7eb;
        }

        .relation-table th {
            color: #6b7280;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            text-align: left;
        }

        .relation-table td {
            font-size: 11px;
        }

        .relation-code,
        .relation-status,
        .relation-date {
            white-space: nowrap;
        }

        .relation-code {
            padding-left: 10px !important;
        }

        .relation-table th:nth-child(3),
        .relation-table th:nth-child(4),
        .relation-table th:nth-child(5),
        .relation-table th:nth-child(6),
        .relation-table td:nth-child(3),
        .relation-table td:nth-child(4),
        .relation-table td:nth-child(5),
        .relation-table td:nth-child(6) {
            text-align: center;
            white-space: nowrap;
        }

        .summary-table td {
            padding: 6px 4px;
            border-bottom: 1px solid #e5e7eb;
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
                                    <td class="meta-label">Data da Venda</td>
                                    <td>{{ $pdfData['sale_date'] }}</td>
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

    <div class="relation-section">
        <div class="relation-title">Itens da Requisicao</div>
        <table class="relation-table">
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
                @forelse ($pdfData['items'] as $item)
                    <tr>
                        <td class="relation-code">{{ $item['product'] }}</td>
                        <td>{{ $item['unit_of_measure'] }}</td>
                        <td>{{ $item['quantity'] }}</td>
                        <td>{{ $item['unit_price'] }}</td>
                        <td>{{ $item['discount_amount'] }}</td>
                        <td>{{ $item['total_amount'] }}</td>
                        <td>{{ $item['observations'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-right">Sem itens.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if (filled($pdfData['observations']))
        <div class="relation-section">
            <div class="relation-title">Observacoes</div>
            <table class="summary-table">
                <tbody>
                    <tr>
                        <td class="meta-label">Observacoes</td>
                        <td class="notes-box">{{ $pdfData['observations'] }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif

    <div class="relation-section">
        <div class="relation-title">Resumo</div>
        <table class="summary-table">
            <tbody>
                @foreach ($pdfData['summary_lines'] as $line)
                <tr>
                    <td class="meta-label">{{ $line['label'] }}</td>
                    <td>{{ $line['value'] }}</td>
                </tr>
                @endforeach
                @if (! empty($pdfData['payment_mode']))
                <tr>
                    <td class="meta-label">Forma de pagamento</td>
                    <td>{{ $pdfData['payment_mode'] }}</td>
                </tr>
                @endif
            </tbody>
        </table>
    </div>

    <div class="signature-block">
        <div class="signature-line">Assinatura do Cliente</div>
    </div>

    <div class="pdf-footer">Gerado em: {{ $pdfData['generated_at'] }}</div>
</body>

</html>
