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
            height: 16px;
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

        .relation-section {
            margin-top: 18px;
        }

        .relation-title {
            margin: 0 0 6px 0;
            padding-bottom: 4px;
            border-bottom: 1px solid #d1d5db;
            color: #17385b;
            font-size: 12px;
            font-weight: bold;
        }

        .relation-table {
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

        .relation-table td:last-child,
        .relation-table th:last-child {
            text-align: right;
        }

        .relation-code,
        .relation-status,
        .relation-date,
        .relation-series {
            white-space: nowrap;
        }

        .relation-code {
            padding-left: 10px !important;
        }

        .item-line + .item-line {
            margin-top: 2px;
        }

        .summary-table td:last-child {
            text-align: right;
            font-weight: bold;
        }

        .summary-table td {
            padding: 6px 4px;
            border-bottom: 1px solid #e5e7eb;
        }

        .summary-total td {
            border-top: 1px solid #d1d5db;
            border-bottom: 0;
            padding-top: 8px;
            font-size: 12px;
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
                                    <td class="meta-label">Data da Fatura</td>
                                    <td>{{ $pdfData['invoice_date'] }}</td>
                                </tr>
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

    @if (! empty($pdfData['fiscal_documents']))
    <div class="relation-section">
        <div class="relation-title">Documentos fiscais vinculados ({{ count($pdfData['fiscal_documents']) }})</div>
        <table class="relation-table">
            <thead>
                <tr>
                    <th>Documento</th>
                    <th>Modelo</th>
                    <th>Série</th>
                    <th>Status</th>
                    <th>Emissão</th>
                    <th>Valor</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pdfData['fiscal_documents'] as $fiscalDocument)
                <tr>
                    <td class="relation-code">{{ $fiscalDocument['number'] }}</td>
                    <td>{{ $fiscalDocument['model'] }}</td>
                    <td class="relation-series">{{ $fiscalDocument['series'] }}</td>
                    <td class="relation-status">{{ $fiscalDocument['status'] }}</td>
                    <td class="relation-date">{{ $fiscalDocument['issued_at'] }}</td>
                    <td>{{ $fiscalDocument['total'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    @if (! empty($pdfData['service_orders']))
    <div class="relation-section">
        <div class="relation-title">Ordens de serviço vinculadas ({{ $pdfData['service_order_count'] }})</div>
        <table class="relation-table">
            <thead>
                <tr>
                    <th>OS</th>
                    <th>Status</th>
                    <th>Itens</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pdfData['service_orders'] as $serviceOrder)
                <tr>
                    <td class="relation-code">{{ $serviceOrder['number'] }}</td>
                    <td class="relation-status">{{ $serviceOrder['status'] }}</td>
                    <td>
                        @forelse ($serviceOrder['items'] as $item)
                        <div class="item-line">{{ $item }}</div>
                        @empty
                        -
                        @endforelse
                    </td>
                    <td>{{ $serviceOrder['total'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    @if (! empty($pdfData['requisitions']))
    <div class="relation-section">
        <div class="relation-title">Requisições vinculadas ({{ $pdfData['requisition_count'] }})</div>
        <table class="relation-table">
            <thead>
                <tr>
                    <th>Requisição</th>
                    <th>Status</th>
                    <th>Itens</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pdfData['requisitions'] as $requisition)
                <tr>
                    <td class="relation-code">{{ $requisition['number'] }}</td>
                    <td class="relation-status">{{ $requisition['status'] }}</td>
                    <td>
                        @forelse ($requisition['items'] as $item)
                        <div class="item-line">{{ $item }}</div>
                        @empty
                        -
                        @endforelse
                    </td>
                    <td>{{ $requisition['total'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    @if (! empty($pdfData['production_orders']))
    <div class="relation-section">
        <div class="relation-title">Ordens de produção vinculadas ({{ $pdfData['production_order_count'] }})</div>
        <table class="relation-table">
            <thead>
                <tr>
                    <th>OP</th>
                    <th>Status</th>
                    <th>Itens</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pdfData['production_orders'] as $productionOrder)
                <tr>
                    <td class="relation-code">{{ $productionOrder['number'] }}</td>
                    <td class="relation-status">{{ $productionOrder['status'] }}</td>
                    <td>
                        @forelse ($productionOrder['items'] as $item)
                        <div class="item-line">{{ $item }}</div>
                        @empty
                        -
                        @endforelse
                    </td>
                    <td>{{ $productionOrder['total'] }}</td>
                </tr>
                @endforeach
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

    <div class="pdf-footer">Gerado em: {{ $pdfData['generated_at'] }}</div>
</body>

</html>
