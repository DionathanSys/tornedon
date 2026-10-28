<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>{{ $receipt ? 'Recibo de pagamento' : 'Demonstrativo da competência' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; margin: 24px; }
        h1 { font-size: 18px; text-align: center; } h2 { font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border-bottom: 1px solid #ddd; padding: 8px; text-align: left; }
        .money { text-align: right; } .note { color: #555; font-size: 10px; }
        .signature { margin-top: 70px; text-align: center; border-top: 1px solid #555; padding-top: 10px; }
    </style>
</head>
<body>
    <h1>{{ $receipt ? 'RECIBO DE PAGAMENTO Nº '.$receipt->id : 'DEMONSTRATIVO DA COMPETÊNCIA' }}</h1>
    <h2>{{ $data['company_name'] }}</h2>
    <p>CPF/CNPJ: {{ $data['company_document'] }}</p>
    <p><strong>Beneficiário:</strong> {{ $data['person_name'] }}<br>
        <strong>CPF/CNPJ:</strong> {{ $data['person_document'] }}<br>
        @if ($data['position']) <strong>Cargo/função:</strong> {{ $data['position'] }}<br> @endif
        <strong>Competência:</strong> {{ $data['competence'] }} — {{ $data['nature'] }}<br>
        <strong>Lançamento:</strong> RH #{{ $data['entry_id'] }}
    </p>
    <table>
        <thead><tr><th>Descrição</th><th class="money">Proventos</th><th class="money">Descontos</th></tr></thead>
        <tbody>
        @foreach ($data['items'] as $item)
            <tr><td>{{ $item['description'] }}</td>
                <td class="money">{{ $item['type'] === 'earning' ? 'R$ '.number_format($item['amount'], 2, ',', '.') : '—' }}</td>
                <td class="money">{{ $item['type'] === 'deduction' ? 'R$ '.number_format($item['amount'], 2, ',', '.') : '—' }}</td></tr>
        @endforeach
        @foreach ($data['advances'] as $advance)
            <tr><td>Abatimento do adiantamento RH #{{ $advance['entry_id'] }}</td><td class="money">—</td><td class="money">R$ {{ number_format($advance['amount'], 2, ',', '.') }}</td></tr>
        @endforeach
        <tr><th>Totais</th><th class="money">R$ {{ number_format($data['gross_amount'], 2, ',', '.') }}</th><th class="money">R$ {{ number_format($data['deduction_amount'], 2, ',', '.') }}</th></tr>
        </tbody>
    </table>
    <p><strong>Líquido previsto:</strong> R$ {{ number_format($data['net_amount'], 2, ',', '.') }}</p>
    @if ($receipt)
        <p>Recebi de {{ $data['company_name'] }} a importância de <strong>R$ {{ number_format($data['payment_amount'], 2, ',', '.') }}</strong>,
            paga em <strong>{{ $data['payment_date'] }}</strong>, referente ao lançamento e à competência acima.</p>
        <p>Encargos desta baixa: juros R$ {{ number_format($data['interest_amount'], 2, ',', '.') }},
            multa R$ {{ number_format($data['fine_amount'], 2, ',', '.') }}, desconto financeiro R$ {{ number_format($data['payment_discount'], 2, ',', '.') }}.</p>
        <p><strong>Total pago até a emissão:</strong> R$ {{ number_format($data['paid_total'], 2, ',', '.') }}<br>
            <strong>Saldo na emissão:</strong> R$ {{ number_format($data['balance_amount'], 2, ',', '.') }}</p>
        <p>{{ $data['balance_amount'] > 0 ? 'Pagamento parcial. Este recibo comprova somente o valor desta baixa.' : 'Saldo do lançamento integralizado na data de emissão.' }}</p>
        <p class="note">Emitido em {{ $receipt->created_at->format('d/m/Y H:i') }}. A quitação refere-se ao valor desta baixa.</p>
        <div class="signature">{{ $data['person_name'] }} — Assinatura do beneficiário</div>
    @else
        <p><strong>Demonstrativo de valores. Este documento não comprova pagamento nem dá quitação.</strong></p>
    @endif
    @if ($data['notes']) <p class="note">Observações: {{ $data['notes'] }}</p> @endif
</body>
</html>
