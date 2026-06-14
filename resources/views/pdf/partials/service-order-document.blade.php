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
