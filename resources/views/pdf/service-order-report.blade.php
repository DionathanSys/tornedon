<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>{{ $report['title'] }}</title>
    @include('pdf.partials.document-styles')
    <style>
        @page {
            margin: 18px 18px 28px 18px;
        }

        body {
            font-size: 9px;
            color: #111827;
        }

        .header {
            margin-bottom: 10px;
        }

        .title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .subtitle {
            color: #6b7280;
            margin-bottom: 8px;
        }

        .meta-table,
        .report-table {
            width: 100%;
            border-collapse: collapse;
        }

        .meta-table td,
        .report-table th,
        .report-table td {
            border: 1px solid #d1d5db;
            padding: 4px;
            vertical-align: top;
        }

        .meta-label,
        .report-table th,
        .summary-row td {
            background: #f3f4f6;
            font-weight: bold;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .pdf-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: right;
            font-size: 8px;
            color: #6b7280;
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="title">{{ $report['title'] }}</div>
        <div class="subtitle">{{ $report['companyName'] }} | Gerado em {{ $report['generatedAt'] }} | Por {{ $report['generatedBy'] }}</div>

        <table class="meta-table">
            <tr>
                <td class="meta-label">Empresa</td>
                <td>{{ $report['companyName'] }}</td>
                <td class="meta-label">Gerado em</td>
                <td>{{ $report['generatedAt'] }}</td>
            </tr>
            <tr>
                <td class="meta-label">Usuário</td>
                <td>{{ $report['generatedBy'] }}</td>
                <td class="meta-label">Registros</td>
                <td>{{ count($report['rows']) }}</td>
            </tr>
        </table>
    </div>

    <table class="report-table">
        <tr>
            @foreach ($report['columns'] as $column)
                <th>{{ $column['label'] }}</th>
            @endforeach
        </tr>

        @forelse ($report['rows'] as $row)
            <tr>
                @foreach ($report['columns'] as $column)
                    <td @class(['text-right' => in_array($column['name'], ['services_total_amount', 'requisition_total_amount', 'grand_total_amount'], true)])>
                        {{ $row[$column['name']] }}
                    </td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ count($report['columns']) }}" class="text-center">Nenhum registro selecionado para exportação.</td>
            </tr>
        @endforelse

        <tr class="summary-row">
            @foreach ($report['columns'] as $column)
                <td @class(['text-right' => in_array($column['name'], ['services_total_amount', 'requisition_total_amount', 'grand_total_amount'], true)])>
                    {{ $report['summary'][$column['name']] ?? '' }}
                </td>
            @endforeach
        </tr>
    </table>

    <div class="pdf-footer">Gerado em: {{ $report['generatedAt'] }}</div>
</body>

</html>
