<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Ordem de Serviço Assinada</title>
    @include('service-orders.partials.public-styles')
</head>
<body>
    <div class="page-shell">
        <section class="message-card">
            <div class="message-icon">✓</div>
            <p class="eyebrow">Assinatura registrada</p>
            <h1>Ordem de serviço assinada</h1>
            <p class="muted">A assinatura da ordem <strong>{{ $pdfData['title'] }}</strong> foi registrada com sucesso.</p>
            @if (filled($pdfData['customer_signature']))
                <img class="signed-image" src="{{ $pdfData['customer_signature'] }}" alt="Assinatura registrada">
            @endif
            @if (filled($pdfData['customer_signed_by_name']))
                <p class="small"><strong>Assinado por:</strong> {{ $pdfData['customer_signed_by_name'] }}</p>
            @endif
            @if (filled($pdfData['customer_signed_at']))
                <p class="muted small">{{ $pdfData['customer_signed_at'] }}</p>
            @endif
        </section>
    </div>
</body>
</html>
