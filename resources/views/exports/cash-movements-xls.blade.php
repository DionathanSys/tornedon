<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #999; padding: 6px; }
        th { background: #f2f2f2; font-weight: bold; }
        .number { mso-number-format: "#,##0.00"; text-align: right; }
    </style>
</head>
<body>
    <table>
        <tr>
            <th colspan="7">{{ $report['title'] }}</th>
        </tr>
        <tr>
            <td colspan="7">Empresa: {{ $report['companyName'] }}</td>
        </tr>
        <tr>
            <td colspan="7">Gerado em: {{ $report['generatedAt'] }} por {{ $report['generatedBy'] }}</td>
        </tr>
        <tr>
            <th>Data</th>
            <th>Conta</th>
            <th>nº Doc</th>
            <th>Descrição</th>
            <th>Valor</th>
            <th>Classificação</th>
            <th>Parceiro</th>
        </tr>
        @foreach ($report['rows'] as $row)
            <tr>
                <td>{{ $row['date'] }}</td>
                <td>{{ $row['account'] }}</td>
                <td>{{ $row['document_number'] }}</td>
                <td>{{ $row['description'] }}</td>
                <td class="number">{{ number_format($row['amount'], 2, ',', '.') }}</td>
                <td>{{ $row['classification'] }}</td>
                <td>{{ $row['partner'] }}</td>
            </tr>
        @endforeach
        <tr>
            <th colspan="4">Total</th>
            <th class="number">{{ number_format($report['total'], 2, ',', '.') }}</th>
            <th colspan="2"></th>
        </tr>
    </table>
</body>
</html>
