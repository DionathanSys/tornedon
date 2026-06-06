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
            margin-bottom: 12px;
            padding-bottom: 8px;
        }

        .header-layout {
            width: 100%;
            border-collapse: collapse;
        }

        .header-layout td {
            vertical-align: top;
            padding: 0;
        }

        .header-main-cell {
            padding-right: 10px;
        }

        .header-logo-cell {
            width: 68px;
            text-align: right;
        }

        .company-logo-wrap {
            width: 64px;
            height: 64px;
            border-radius: 8px;
            background: #ffffff;
            text-align: center;
            padding: 0;
            overflow: hidden;
            display: inline-block;
        }

        .company-logo {
            width: 58px;
            height: 58px;
            display: block;
            margin: 0 auto;
        }

        .header-kicker {
            margin-bottom: 2px;
            color: #6b7280;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .page-header-title {
            margin: 0;
            color: #111827;
            font-size: 18px;
            font-weight: bold;
        }

        .page-header-subtitle {
            margin-top: 2px;
            color: #4b5563;
            font-size: 10px;
        }

        .header-meta-grid {
            margin-top: 8px;
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 4px;
        }

        .header-meta-grid td {
            width: 50%;
            padding: 0 8px 0 0;
            vertical-align: top;
        }

        .header-meta-card {
            min-height: 28px;
            padding: 2px 0;
        }

        .header-meta-label {
            margin-bottom: 1px;
            color: #6b7280;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .header-meta-value {
            color: #111827;
            font-size: 10px;
            font-weight: bold;
            line-height: 1.25;
        }

        .header-meta-secondary {
            margin-top: 2px;
            color: #6b7280;
            font-size: 9px;
            font-style: italic;
            line-height: 1.2;
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

        .relation-table th:nth-child(2),
        .relation-table th:nth-child(3),
        .relation-table th:nth-child(4),
        .relation-table th:nth-child(5),
        .relation-table td:nth-child(2),
        .relation-table td:nth-child(3),
        .relation-table td:nth-child(4),
        .relation-table td:nth-child(5) {
            text-align: center;
            white-space: nowrap;
        }

        .summary-table td {
            padding: 6px 4px;
            border-bottom: 1px solid #e5e7eb;
        }

        .summary-value {
            text-align: right;
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

        .signature-responsible {
            margin-top: 14px;
            font-size: 11px;
            color: #111827;
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
        <table class="header-layout">
            <tr>
                <td class="header-main-cell">
                    <div class="header-kicker">Ordem de Serviço</div>
                    <div class="page-header-title">{{ $pdfData['title'] }}</div>

                    @php
                        $headerDetails = array_merge(
                            $pdfData['header_lines'],
                            [['label' => 'Data da Ordem', 'value' => $pdfData['order_date']]],
                            $pdfData['responsibles']
                        );
                    @endphp
                    <table class="header-meta-grid">
                        <tbody>
                            @foreach (array_chunk($headerDetails, 2) as $row)
                                <tr>
                                    @foreach ($row as $line)
                                        <td>
                                            <div class="header-meta-card">
                                                <div class="header-meta-label">{{ $line['label'] }}</div>
                                                <div class="header-meta-value {{ $line['class'] ?? '' }}">{{ $line['value'] }}</div>
                                                @if (filled($line['secondary_value'] ?? null))
                                                    <div class="header-meta-secondary">{{ $line['secondary_value'] }}</div>
                                                @endif
                                            </div>
                                        </td>
                                    @endforeach
                                    @if (count($row) === 1)
                                        <td></td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </td>
                <td class="header-logo-cell">
                    @if (filled($pdfData['company_logo']))
                        <div class="company-logo-wrap">
                            <img src="{{ $pdfData['company_logo'] }}" alt="Logo {{ $pdfData['company_name'] }}" class="company-logo">
                        </div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="relation-section">
        <div class="relation-title">Serviços</div>
        <table class="relation-table">
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
                    <td class="relation-code">{{ $item['service'] }}</td>
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
    </div>

    @if (! empty($pdfData['requisition']))
    <div class="relation-section">
        <div class="relation-title">{{ $pdfData['requisition']['title'] }}</div>
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
                @forelse ($pdfData['requisition']['items'] as $item)
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
                    <td colspan="7" class="text-right">Sem produtos vinculados.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endif

    @if (filled($pdfData['customer_observations']) || filled($pdfData['solution']) || filled($pdfData['technician_observations']))
    <div class="relation-section">
        <div class="relation-title">Observações</div>
        <table class="summary-table">
            <tbody>
                @if (filled($pdfData['customer_observations']))
                <tr>
                    <td class="meta-label">Observações</td>
                    <td>{{ $pdfData['customer_observations'] }}</td>
                </tr>
                @endif
                @if (filled($pdfData['solution']))
                <tr>
                    <td class="meta-label">Solução aplicada</td>
                    <td>{{ $pdfData['solution'] }}</td>
                </tr>
                @endif
                @if (filled($pdfData['technician_observations']))
                <tr>
                    <td class="meta-label">Observações Técnico</td>
                    <td>{{ $pdfData['technician_observations'] }}</td>
                </tr>
                @endif
            </tbody>
        </table>
    </div>
    @endif

    @if (filled($pdfData['additional_info_text']))
    <div class="relation-section">
        <div class="relation-title">Informacoes Adicionais</div>
        <div class="notes-box">{{ $pdfData['additional_info_text'] }}</div>
    </div>
    @endif

    <div class="relation-section">
        <div class="relation-title">Resumo</div>
        <table class="summary-table">
            <tbody>
                @foreach ($pdfData['summary_lines'] as $line)
                <tr>
                    <td class="meta-label">{{ $line['label'] }}</td>
                    <td class="summary-value">{{ $line['value'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="signature-block">
        @if (filled($pdfData['customer_signature']))
        <img src="{{ $pdfData['customer_signature'] }}" alt="Assinatura do cliente" class="signature-image">
        <div class="signature-line">{{ $pdfData['follow_up_responsible_name'] ?: 'Assinatura do Cliente' }}</div>
        @if (filled($pdfData['customer_signed_at']))
        <div class="signature-date">Assinado em {{ $pdfData['customer_signed_at'] }}</div>
        @endif
        @else
        <div class="signature-line">{{ $pdfData['follow_up_responsible_name'] ?: 'Assinatura do Cliente' }}</div>
        @endif
    </div>

    <div class="pdf-footer">Gerado em: {{ $pdfData['generated_at'] }}</div>
</body>

</html>
