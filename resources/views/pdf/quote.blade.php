<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>{{ $pdfData['title'] }}</title>
    @include('pdf.partials.document-styles')
    <style>
        @page { margin: 24px 24px 34px; }
        body { color: #111827; padding-bottom: 28px; }
        .header, .meta, .items, .summary { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: top; }
        .kicker, th { color: #6b7280; font-size: 9px; font-weight: bold; letter-spacing: .05em; text-transform: uppercase; }
        .title { margin: 3px 0 8px; font-size: 18px; font-weight: bold; }
        .logo { width: 58px; height: 58px; object-fit: contain; }
        .meta td { width: 50%; padding: 4px 8px 4px 0; vertical-align: top; }
        .meta-label { color: #6b7280; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        .meta-value { font-size: 10px; font-weight: bold; line-height: 1.3; }
        .meta-secondary { color: #6b7280; font-size: 9px; font-style: italic; }
        .section { margin-top: 18px; }
        .section-title { border-bottom: 1px solid #d1d5db; color: #17385b; font-size: 12px; font-weight: bold; margin-bottom: 6px; padding-bottom: 4px; }
        .items th, .items td { border-bottom: 1px solid #e5e7eb; padding: 6px 4px; vertical-align: top; }
        .items td { font-size: 10px; }
        .items th:not(:first-child), .items td:not(:first-child) { text-align: center; white-space: nowrap; }
        .summary td { border: 1px solid #d1d5db; padding: 7px 8px; }
        .summary td:first-child { background: #f8fafc; color: #17385b; font-weight: bold; width: 22%; }
        .summary td:last-child { text-align: right; }
        .notes { white-space: pre-line; }
        .footer { bottom: 0; color: #6b7280; font-size: 10px; position: fixed; right: 0; text-align: right; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <div class="kicker">Orçamento</div>
                <div class="title">{{ $pdfData['title'] }}</div>
                <table class="meta">
                    @foreach (array_chunk($pdfData['header_lines'], 2) as $row)
                        <tr>
                            @foreach ($row as $line)
                                <td>
                                    <div class="meta-label">{{ $line['label'] }}</div>
                                    <div class="meta-value">{{ $line['value'] }}</div>
                                    @if (filled($line['secondary_value'] ?? null))
                                        <div class="meta-secondary">{{ $line['secondary_value'] }}</div>
                                    @endif
                                </td>
                            @endforeach
                            @if (count($row) === 1)<td></td>@endif
                        </tr>
                    @endforeach
                </table>
            </td>
            <td style="width: 68px; text-align: right;">
                @if (filled($pdfData['company_logo']))
                    <img src="{{ $pdfData['company_logo'] }}" alt="Logo {{ $pdfData['company_name'] }}" class="logo">
                @endif
            </td>
        </tr>
    </table>

    <div class="section">
        <div class="section-title">Itens do Orçamento</div>
        <table class="items">
            <thead><tr><th>Descrição</th><th>Unidade</th><th>Qtd.</th><th>Valor Unit.</th><th>Desconto</th><th>Total</th></tr></thead>
            <tbody>
                @forelse ($pdfData['items'] as $item)
                    <tr><td>{{ $item['description'] }}</td><td>{{ $item['unit_of_measure'] }}</td><td>{{ $item['quantity'] }}</td><td>{{ $item['unit_price'] }}</td><td>{{ $item['discount_amount'] }}</td><td>{{ $item['total_amount'] }}</td></tr>
                @empty
                    <tr><td colspan="6">Sem itens.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if (filled($pdfData['description']) || filled($pdfData['customer_observations']))
        <div class="section">
            <div class="section-title">Observações</div>
            <table class="summary">
                @if (filled($pdfData['description']))<tr><td>Descrição</td><td class="notes">{{ $pdfData['description'] }}</td></tr>@endif
                @if (filled($pdfData['customer_observations']))<tr><td>Observações do cliente</td><td class="notes">{{ $pdfData['customer_observations'] }}</td></tr>@endif
            </table>
        </div>
    @endif

    <div class="section">
        <div class="section-title">Resumo</div>
        <table class="summary">
            @foreach ($pdfData['summary_lines'] as $line)<tr><td>{{ $line['label'] }}</td><td>{{ $line['value'] }}</td></tr>@endforeach
        </table>
    </div>
    <div class="footer">Gerado em: {{ $pdfData['generated_at'] }}</div>
</body>
</html>
