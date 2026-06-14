<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Ordens de Serviço</title>
    @include('pdf.partials.document-styles')
    <style>
        @page {
            margin: 24px 24px 34px 24px;
        }

        body {
            padding-bottom: 28px;
            color: #111827;
        }

        .service-order-page {
            page-break-after: always;
        }

        .service-order-page:last-child {
            page-break-after: auto;
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
            margin-top: 56px;
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
    @foreach ($documents as $document)
        <div class="service-order-page">
            @php
                $record = $document['record'];
                $pdfData = $document['pdfData'];
            @endphp

            @include('pdf.partials.service-order-document', ['record' => $record, 'pdfData' => $pdfData])
        </div>
    @endforeach
</body>

</html>
