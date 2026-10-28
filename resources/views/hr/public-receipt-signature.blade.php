<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    @include('partials.translation-meta')
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Assinatura do recibo de pagamento</title>
    @include('service-orders.partials.public-styles')
</head>
<body>
    @php $data = $receipt->snapshot; $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.'); @endphp
    <div class="page-shell">
        <header class="public-header">
            <div><p class="eyebrow">{{ $data['company_name'] }}</p><h1>{{ $receipt->signature ? 'Recibo assinado' : 'Assinatura do recibo' }}</h1><p class="muted small">Recibo #{{ $receipt->id }} · {{ $data['nature'] }} · Competência {{ $data['competence'] }}</p></div>
            <div class="header-side"><p class="order-title">{{ $money($data['payment_amount']) }}</p><p class="muted small">Link válido até {{ $link->expires_at->format('d/m/Y H:i') }}</p></div>
        </header>
        @if ($errors->any())<div class="alert" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="content-grid">
            <main class="stack">
                <section class="section">
                    <h2>Dados do pagamento</h2>
                    <div class="meta-grid">
                        <div class="meta-card"><div class="meta-label">Beneficiário</div><div class="meta-value">{{ $data['person_name'] }}</div></div>
                        <div class="meta-card"><div class="meta-label">CPF/CNPJ</div><div class="meta-value">{{ $data['person_document'] }}</div></div>
                        <div class="meta-card"><div class="meta-label">Data do pagamento</div><div class="meta-value">{{ $data['payment_date'] }}</div></div>
                        <div class="meta-card"><div class="meta-label">Valor desta baixa</div><div class="meta-value">{{ $money($data['payment_amount']) }}</div></div>
                    </div>
                    <p class="notes">A assinatura confirma o recebimento apenas do valor desta baixa. Saldo na emissão: {{ $money($data['balance_amount']) }}.</p>
                    <a class="clear-button" href="{{ route('hr.signature.pdf', ['token' => $token]) }}" target="_blank" rel="noopener noreferrer">{{ $receipt->signature ? 'Abrir PDF assinado' : 'Conferir recibo completo em PDF' }}</a>
                </section>
                <section class="section">
                    <h2>Composição da competência</h2>
                    <div class="table-wrap"><table>
                        <thead><tr><th>Descrição</th><th class="number">Proventos</th><th class="number">Descontos</th></tr></thead>
                        <tbody>@foreach ($data['items'] as $item)<tr><td>{{ $item['description'] }}</td><td class="number">{{ $item['type'] === 'earning' ? $money($item['amount']) : '—' }}</td><td class="number">{{ $item['type'] === 'deduction' ? $money($item['amount']) : '—' }}</td></tr>@endforeach
                        @foreach ($data['advances'] as $advance)<tr><td>Adiantamento RH #{{ $advance['entry_id'] }}</td><td>—</td><td class="number">{{ $money($advance['amount']) }}</td></tr>@endforeach</tbody>
                    </table></div>
                    <p class="notes">Líquido da competência: {{ $money($data['net_amount']) }}.</p>
                </section>
            </main>
            <aside class="stack">
                <section class="section signature-panel">
                    @if ($receipt->signature)
                        <h2>Assinatura concluída</h2>
                        <p>{{ $receipt->signature->signed_by_name }}</p>
                        <p class="muted">Assinado eletronicamente em {{ $receipt->signature->signed_at->format('d/m/Y') }} às {{ $receipt->signature->signed_at->format('H:i:s') }}.</p>
                        <p class="muted small">O PDF do recibo já contém a assinatura e o carimbo de data e hora do ato.</p>
                    @else
                        <h2>Assine o recibo</h2>
                        <p class="signature-intro muted small">Confira os dados e o PDF antes de confirmar o recebimento.</p>
                        <form id="hr-receipt-signature-form" method="post" action="{{ route('hr.signature.store', ['token' => $token]) }}">
                            @csrf
                            <div class="field"><label for="signed_by_name">Nome completo de quem assina</label><input id="signed_by_name" name="signed_by_name" type="text" value="{{ old('signed_by_name') }}" maxlength="255" autocomplete="name" required></div>
                            <div class="field"><label for="signature-pad">Assinatura</label><div class="canvas-frame"><canvas id="signature-pad" aria-label="Campo para desenhar sua assinatura"></canvas></div>
                                <p class="canvas-help">Assine com o dedo, mouse ou caneta.</p><div class="signature-tools"><span class="muted small">A data e hora serão registradas ao confirmar.</span><button id="clear-signature" class="clear-button" type="button">Limpar</button></div>
                                <input id="signature" name="signature" type="hidden">
                            </div>
                            <label class="agreement" for="agreement"><input id="agreement" name="agreement" type="checkbox" value="1" required><span>Li o recibo e confirmo o recebimento de {{ $money($data['payment_amount']) }} referente ao pagamento indicado.</span></label>
                            <button id="submit-signature" class="submit-button" type="submit">Confirmar assinatura</button>
                        </form>
                    @endif
                </section>
            </aside>
        </div>
    </div>
    @unless ($receipt->signature)
        @include('hr.partials.public-signature-script')
    @endunless
</body>
</html>
