<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .meta { color: #4b5563; margin-bottom: 12px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #d1d5db; padding: 5px; vertical-align: top; }
        th { background: #f3f4f6; font-weight: bold; }
        .right { text-align: right; white-space: nowrap; }
        .nowrap { white-space: nowrap; }
        .description { width: 24%; }
    </style>
</head>
<body>
    <h1>{{ $report['title'] }}</h1>
    <div class="meta">
        Empresa: {{ $report['companyName'] }}<br>
        Gerado em: {{ $report['generatedAt'] }} por {{ $report['generatedBy'] }}
    </div>

    <table>
        <thead>
            <tr>
                <th class="nowrap">Data</th>
                <th>Conta</th>
                <th class="nowrap">nº Doc</th>
                <th class="description">Descrição</th>
                <th class="right">Valor</th>
                <th>Classificação</th>
                <th>Parceiro</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    <td class="nowrap">{{ $row['date'] }}</td>
                    <td>{{ $row['account'] }}</td>
                    <td class="nowrap">{{ $row['document_number'] }}</td>
                    <td>{{ $row['description'] }}</td>
                    <td class="right">{{ number_format($row['amount'], 2, ',', '.') }}</td>
                    <td>{{ $row['classification'] }}</td>
                    <td>{{ $row['partner'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">Nenhum movimento encontrado.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <th colspan="4" class="right">Total</th>
                <th class="right">{{ number_format($report['total'], 2, ',', '.') }}</th>
                <th colspan="2"></th>
            </tr>
        </tfoot>
    </table>
</body>
</html>
