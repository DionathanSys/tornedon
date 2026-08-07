<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>{{ $report['title'] }}</title>
    @include('pdf.partials.document-styles')
    <style>
        @page { margin: 18px 18px 28px 18px; }

        body {
            font-size: 9px;
            color: #111827;
        }

        .header { margin-bottom: 10px; }
        .title { font-size: 16px; font-weight: bold; margin-bottom: 4px; }
        .subtitle { color: #6b7280; margin-bottom: 8px; }

        .meta-table,
        .summary-table,
        .report-table {
            width: 100%;
            border-collapse: collapse;
        }

        .meta-table td,
        .summary-table td,
        .report-table th,
        .report-table td {
            border: 1px solid #d1d5db;
            padding: 4px;
            vertical-align: top;
        }

        .meta-label,
        .summary-label,
        .report-table th,
        .summary-row td {
            background: #f3f4f6;
            font-weight: bold;
        }

        .summary-table { margin: 10px 0; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .positive { color: #047857; }
        .negative { color: #b91c1c; }

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
    @php
        $money = fn (float $amount): string => 'R$ ' . number_format($amount, 2, ',', '.');
        $decimal = fn (float $amount, int $decimals = 2): string => number_format($amount, $decimals, ',', '.');
    @endphp

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
            @foreach ($report['filters'] as $label => $value)
                <tr>
                    <td class="meta-label">{{ $label }}</td>
                    <td colspan="3">{{ $value }}</td>
                </tr>
            @endforeach
        </table>
    </div>

    <table class="summary-table">
        <tr>
            <td class="summary-label">Valor vendido</td>
            <td class="text-right">{{ $money($report['summary']['sold_amount']) }}</td>
            <td class="summary-label">Custo</td>
            <td class="text-right">{{ $money($report['summary']['cost_amount']) }}</td>
            <td class="summary-label">Lucro</td>
            <td class="text-right {{ $report['summary']['profit_amount'] >= 0 ? 'positive' : 'negative' }}">{{ $money($report['summary']['profit_amount']) }}</td>
            <td class="summary-label">Margem</td>
            <td class="text-right {{ $report['summary']['margin_percent'] >= 0 ? 'positive' : 'negative' }}">{{ $decimal($report['summary']['margin_percent']) }}%</td>
        </tr>
        <tr>
            <td class="summary-label">Produtos</td>
            <td class="text-right">{{ number_format($report['summary']['products_count'], 0, ',', '.') }}</td>
            <td class="summary-label">Vendas</td>
            <td class="text-right">{{ number_format($report['summary']['sales_count'], 0, ',', '.') }}</td>
            <td class="summary-label">Quantidade</td>
            <td class="text-right">{{ $decimal($report['summary']['quantity'], 3) }}</td>
            <td class="summary-label">Itens sem custo</td>
            <td class="text-right">{{ number_format($report['summary']['missing_cost_items'], 0, ',', '.') }}</td>
        </tr>
    </table>

    <table class="report-table">
        <tr>
            <th>Produto</th>
            <th>Un.</th>
            <th class="text-right">Qtde.</th>
            <th class="text-right">Vendas</th>
            <th class="text-right">Valor vendido</th>
            <th class="text-right">Custo</th>
            <th class="text-right">Lucro</th>
            <th class="text-right">Margem</th>
            <th class="text-right">Sem custo</th>
        </tr>

        @forelse ($report['rows'] as $row)
            <tr>
                <td>{{ $row['product_code'] !== '' ? '[' . $row['product_code'] . '] ' : '' }}{{ $row['product_name'] }}</td>
                <td class="text-center">{{ $row['unit_of_measure'] }}</td>
                <td class="text-right">{{ $decimal($row['quantity'], 3) }}</td>
                <td class="text-right">{{ number_format($row['sales_count'], 0, ',', '.') }}</td>
                <td class="text-right">{{ $money($row['sold_amount']) }}</td>
                <td class="text-right">{{ $money($row['cost_amount']) }}</td>
                <td class="text-right {{ $row['profit_amount'] >= 0 ? 'positive' : 'negative' }}">{{ $money($row['profit_amount']) }}</td>
                <td class="text-right {{ $row['margin_percent'] >= 0 ? 'positive' : 'negative' }}">{{ $decimal($row['margin_percent']) }}%</td>
                <td class="text-right">{{ number_format($row['missing_cost_items'], 0, ',', '.') }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="9" class="text-center">Nenhuma venda de produto encontrada para os filtros informados.</td>
            </tr>
        @endforelse

        <tr class="summary-row">
            <td colspan="2">Total</td>
            <td class="text-right">{{ $decimal($report['summary']['quantity'], 3) }}</td>
            <td class="text-right">{{ number_format($report['summary']['sales_count'], 0, ',', '.') }}</td>
            <td class="text-right">{{ $money($report['summary']['sold_amount']) }}</td>
            <td class="text-right">{{ $money($report['summary']['cost_amount']) }}</td>
            <td class="text-right">{{ $money($report['summary']['profit_amount']) }}</td>
            <td class="text-right">{{ $decimal($report['summary']['margin_percent']) }}%</td>
            <td class="text-right">{{ number_format($report['summary']['missing_cost_items'], 0, ',', '.') }}</td>
        </tr>
    </table>

    <div class="pdf-footer">Gerado em: {{ $report['generatedAt'] }}</div>
</body>

</html>
