<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>{{ $receipt ? 'Recibo de pagamento nº '.$receipt->id : 'Demonstrativo da competência' }}</title>
    <style>
        @page { margin: 30px 36px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; line-height: 1.5; color: #273447; }
        table { width: 100%; border-collapse: collapse; }
        .header { border-bottom: 3px solid #253b55; padding-bottom: 16px; margin-bottom: 20px; }
        .header td { vertical-align: top; }
        .brand { color: #253b55; font-size: 16px; font-weight: bold; }
        .eyebrow { font-size: 8px; letter-spacing: 1.5px; text-transform: uppercase; color: #6b7c90; }
        .document { text-align: right; } .document strong { font-size: 13px; color: #253b55; }
        .document span { display: block; margin-top: 5px; }
        h1 { font-size: 19px; color: #253b55; margin: 0 0 4px; letter-spacing: .5px; }
        .subtitle { color: #6b7c90; margin: 0 0 20px; }
        .box { border: 1px solid #dce3eb; background: #f8fafc; padding: 12px 14px; margin-bottom: 14px; }
        .identity td { vertical-align: top; padding: 0 10px 0 0; }
        .label { font-size: 8px; text-transform: uppercase; letter-spacing: .6px; color: #718096; }
        .value { font-size: 11px; font-weight: bold; color: #253b55; }
        .section-title { font-size: 10px; font-weight: bold; color: #253b55; text-transform: uppercase; letter-spacing: .8px; margin: 19px 0 8px; }
        .items th { background: #253b55; color: white; padding: 9px 10px; font-size: 9px; text-align: left; }
        .items td { border-bottom: 1px solid #e5eaf0; padding: 8px 10px; }
        .items .alternate { background: #f8fafc; }
        .items .money, .money { text-align: right; white-space: nowrap; }
        .totals { margin-top: 12px; }
        .totals td { width: 33.33%; padding: 10px 12px; border: 1px solid #dce3eb; }
        .totals strong { display: block; font-size: 14px; color: #253b55; margin-top: 4px; }
        .paid { padding: 15px 18px; border: 1px solid #c9d8e6; background: #edf3f8; margin: 18px 0 14px; }
        .paid td { vertical-align: middle; } .paid-amount { font-size: 22px; font-weight: bold; text-align: right; color: #253b55; }
        .declaration { text-align: justify; margin: 14px 0; }
        .notes { font-size: 9px; color: #64748b; white-space: pre-line; }
        .notice { border-left: 3px solid #718096; background: #f8fafc; padding: 9px 12px; font-size: 9px; margin: 12px 0; }
        .signature-block { page-break-inside: avoid; margin-top: 28px; text-align: center; }
        .signature-center { width: 62%; margin: 0 auto; text-align: center; }
        .signature-line { border-top: 1px solid #526476; padding-top: 7px; font-size: 9px; }
        .signature-image { height: 70px; margin: 0 auto 3px; }
        .signature-image img { max-height: 70px; max-width: 260px; }
        .manual-space { height: 54px; }
        .signature-stamp { display: inline-block; margin-top: 10px; padding: 5px 14px; border: 1px solid #c9d8e6; background: #f0f4f8; color: #253b55; font-size: 8px; }
        .manual-date { margin-top: 22px; font-size: 10px; color: #526476; }
        .footer { margin-top: 26px; border-top: 1px solid #dce3eb; padding-top: 9px; color: #718096; font-size: 8px; }
        .hash { word-break: break-all; font-size: 7px; margin-top: 4px; }
    </style>
</head>
<body>
    @php
        $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');
        $signature = $receipt?->signature;
        $city = implode(' / ', array_filter([$data['company_city'] ?? null, $data['company_state'] ?? null]));
    @endphp
    <div class="header">
        <table><tr>
            <td style="width: 68%"><div class="eyebrow">Recursos Humanos</div><div class="brand">{{ $data['company_name'] }}</div><div>CPF/CNPJ: {{ $data['company_document'] }}</div>@if ($city)<div>{{ $city }}</div>@endif</td>
            <td class="document"><strong>{{ $receipt ? 'RECIBO Nº '.str_pad($receipt->id, 6, '0', STR_PAD_LEFT) : 'DEMONSTRATIVO' }}</strong><span>Competência {{ $data['competence'] }}</span><span>Referência RH #{{ $data['entry_id'] }}</span></td>
        </tr></table>
    </div>

    <h1>{{ $receipt ? 'RECIBO DE PAGAMENTO' : 'DEMONSTRATIVO DA COMPETÊNCIA' }}</h1>
    <p class="subtitle">{{ $data['nature'] }} · Competência {{ $data['competence'] }}</p>

    <div class="box">
        <table class="identity"><tr>
            <td style="width: 64%"><div class="label">Beneficiário</div><div class="value">{{ $data['person_name'] }}</div><div>{{ $data['position'] ?: 'Funcionário / sócio' }}</div></td>
            <td><div class="label">CPF/CNPJ</div><div class="value">{{ $data['person_document'] }}</div><div>Vencimento: {{ $data['due_date'] ?? '—' }}</div></td>
        </tr></table>
    </div>

    <div class="section-title">Composição da competência</div>
    <table class="items">
        <thead><tr><th style="width: 58%">Descrição</th><th class="money">Proventos</th><th class="money">Descontos</th></tr></thead>
        <tbody>
            @foreach ($data['items'] as $item)
                <tr class="{{ $loop->even ? 'alternate' : '' }}"><td>{{ $item['description'] }}</td><td class="money">{{ $item['type'] === 'earning' ? $money($item['amount']) : '—' }}</td><td class="money">{{ $item['type'] === 'deduction' ? $money($item['amount']) : '—' }}</td></tr>
            @endforeach
            @foreach ($data['advances'] as $advance)
                <tr><td>Abatimento de adiantamento · RH #{{ $advance['entry_id'] }}</td><td class="money">—</td><td class="money">{{ $money($advance['amount']) }}</td></tr>
            @endforeach
        </tbody>
    </table>
    <table class="totals"><tr>
        <td><div class="label">Total de proventos</div><strong>{{ $money($data['gross_amount']) }}</strong></td>
        <td><div class="label">Total de descontos</div><strong>{{ $money($data['deduction_amount']) }}</strong></td>
        <td style="background: #f0f4f8"><div class="label">Líquido da competência</div><strong>{{ $money($data['net_amount']) }}</strong></td>
    </tr></table>

    @if ($receipt)
        <div class="paid"><table><tr><td><div class="label">Valor efetivamente pago nesta baixa</div><strong>Pagamento em {{ $data['payment_date'] }}</strong><div>Comprovante da baixa #{{ $receipt->payment_id }}</div></td><td class="paid-amount">{{ $money($data['payment_amount']) }}</td></tr></table></div>
        <p class="declaration">Declaro ter recebido de <strong>{{ $data['company_name'] }}</strong>, CPF/CNPJ {{ $data['company_document'] }}, a importância de <strong>{{ $money($data['payment_amount']) }}</strong>, referente a <strong>{{ mb_strtolower($data['nature']) }}</strong> da competência <strong>{{ $data['competence'] }}</strong>, conforme a composição acima. Dou quitação exclusivamente ao valor deste pagamento.</p>
        @if ($data['interest_amount'] || $data['fine_amount'] || $data['payment_discount'])
            <p class="notes">Ajustes financeiros desta baixa: juros {{ $money($data['interest_amount']) }} · multa {{ $money($data['fine_amount']) }} · desconto {{ $money($data['payment_discount']) }}.</p>
        @endif
        <table class="identity"><tr><td><span class="label">Total pago até a emissão</span><br><strong>{{ $money($data['paid_total']) }}</strong></td><td class="money"><span class="label">Saldo na emissão</span><br><strong>{{ $money($data['balance_amount']) }}</strong></td></tr></table>
        @if ($data['balance_amount'] > 0)<div class="notice">Pagamento parcial. Este recibo não representa quitação do saldo restante da competência.</div>@endif

        @if ($data['notes'])<p class="notes">Observações: {{ $data['notes'] }}</p>@endif

        <div class="signature-block">
            <div class="signature-center">
                @if ($signature)
                    <div class="signature-image"><img src="{{ $signature->signature }}" alt="Assinatura do beneficiário"></div>
                @else
                    <div class="manual-space"></div>
                @endif
                <div class="signature-line"><strong>{{ $signature?->signed_by_name ?? $data['person_name'] }}</strong><br>CPF/CNPJ: {{ $data['person_document'] }}<br>Assinatura do beneficiário</div>
                @if ($signature)
                    <div class="signature-stamp">ASSINADO ELETRONICAMENTE<br>{{ $signature->signed_at->format('d/m/Y') }} às {{ $signature->signed_at->format('H:i:s') }}</div>
                @else
                    <div class="manual-date">{{ $city ? $city.' · ' : '' }}Data: ____ / ____ / ________</div>
                @endif
            </div>
        </div>
        <div class="footer">Recibo nº {{ $receipt->id }} · Lançamento RH #{{ $data['entry_id'] }} · Emitido em {{ $receipt->created_at->format('d/m/Y H:i') }}
            @if ($signature)<div class="hash">Identificação do documento assinado: {{ $signature->document_hash }}</div>@endif
        </div>
    @else
        <div class="notice"><strong>Documento para conferência.</strong> Este demonstrativo não comprova pagamento e não dá quitação de valores.</div>
        @if ($data['notes'])<p class="notes">Observações: {{ $data['notes'] }}</p>@endif
        <div class="footer">Demonstrativo da competência {{ $data['competence'] }} · Lançamento RH #{{ $data['entry_id'] }}</div>
    @endif
</body>
</html>
