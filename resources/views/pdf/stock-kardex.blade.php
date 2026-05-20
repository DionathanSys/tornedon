<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>{{ $pdfData['title'] }}</title>
    @include('pdf.partials.document-styles')
    <style>
        @page {
            margin: 18px 18px 28px 18px;
        }

        body {
            font-size: 10px;
            color: #111827;
        }

        .header {
            margin-bottom: 10px;
        }

        .title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 4px;
        }

        .subtitle {
            color: #6b7280;
            margin-bottom: 8px;
        }

        .meta-table,
        .summary-table,
        .kardex-table {
            width: 100%;
            border-collapse: collapse;
        }

        .meta-table td,
        .summary-table td,
        .summary-table th,
        .kardex-table th,
        .kardex-table td {
            border: 1px solid #d1d5db;
            padding: 5px;
            vertical-align: top;
        }

        .meta-label,
        .summary-table th,
        .kardex-table th {
            background: #f3f4f6;
            font-weight: bold;
        }

        .section {
            margin-top: 12px;
        }

        .section-title {
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 6px;
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
            font-size: 9px;
            color: #6b7280;
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="title">{{ $pdfData['title'] }}</div>
        <div class="subtitle">{{ $pdfData['company_name'] }} | Gerado em {{ $pdfData['generated_at'] }}</div>

        <table class="meta-table">
            <tr>
                <td class="meta-label">Produto</td>
                <td>[{{ $pdfData['product']['code'] }}] {{ $pdfData['product']['name'] }}</td>
                <td class="meta-label">Unidade</td>
                <td>{{ $pdfData['product']['unit'] }}</td>
            </tr>
            <tr>
                <td class="meta-label">Período</td>
                <td>{{ $pdfData['period']['start'] }} a {{ $pdfData['period']['end'] }}</td>
                <td class="meta-label">Saldo Inicial</td>
                <td>{{ $pdfData['opening']['stock_balance'] }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Resumo de Saldos</div>
        <table class="summary-table">
            <tr>
                <th>Saldo Inicial</th>
                <th>Reservado Inicial</th>
                <th>Disponível Inicial</th>
                <th>Entradas</th>
                <th>Saídas</th>
                <th>Reservas</th>
                <th>Liberações</th>
                <th>Saldo Final</th>
                <th>Reservado Final</th>
                <th>Disponível Final</th>
            </tr>
            <tr>
                <td class="text-right">{{ $pdfData['opening']['stock_balance'] }}</td>
                <td class="text-right">{{ $pdfData['opening']['reserved_balance'] }}</td>
                <td class="text-right">{{ $pdfData['opening']['available_balance'] }}</td>
                <td class="text-right">{{ $pdfData['summary']['entries'] }}</td>
                <td class="text-right">{{ $pdfData['summary']['exits'] }}</td>
                <td class="text-right">{{ $pdfData['summary']['reservations'] }}</td>
                <td class="text-right">{{ $pdfData['summary']['releases'] }}</td>
                <td class="text-right">{{ $pdfData['summary']['closing_stock_balance'] }}</td>
                <td class="text-right">{{ $pdfData['summary']['closing_reserved_balance'] }}</td>
                <td class="text-right">{{ $pdfData['summary']['closing_available_balance'] }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Movimentações</div>
        <table class="kardex-table">
            <tr>
                <th>Data</th>
                <th>Tipo</th>
                <th>Referência</th>
                <th>Usuário</th>
                <th>Qtde. Oper.</th>
                <th>Qtde. Base</th>
                <th>Entrada</th>
                <th>Saída</th>
                <th>Reserva</th>
                <th>Liberação</th>
                <th>Saldo</th>
                <th>Reservado</th>
                <th>Disponível</th>
                <th>Custo Un.</th>
                <th>Custo Total</th>
            </tr>
            @forelse ($pdfData['rows'] as $row)
                <tr>
                    <td>{{ $row['date'] }}</td>
                    <td>{{ $row['type'] }}</td>
                    <td>{{ $row['reference'] }}</td>
                    <td>{{ $row['user'] }}</td>
                    <td class="text-right">{{ $row['operational_quantity'] }} {{ $row['operational_unit'] }}</td>
                    <td class="text-right">{{ $row['base_quantity'] }} {{ $row['base_unit'] }}</td>
                    <td class="text-right">{{ $row['entry_quantity'] }}</td>
                    <td class="text-right">{{ $row['exit_quantity'] }}</td>
                    <td class="text-right">{{ $row['reservation_quantity'] }}</td>
                    <td class="text-right">{{ $row['release_quantity'] }}</td>
                    <td class="text-right">{{ $row['stock_balance'] }}</td>
                    <td class="text-right">{{ $row['reserved_balance'] }}</td>
                    <td class="text-right">{{ $row['available_balance'] }}</td>
                    <td class="text-right">{{ $row['unit_price'] }}</td>
                    <td class="text-right">{{ $row['total_amount'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="15" class="text-center">Nenhuma movimentação encontrada para o período informado.</td>
                </tr>
            @endforelse
        </table>
    </div>

    <div class="pdf-footer">Gerado em: {{ $pdfData['generated_at'] }}</div>
</body>

</html>
