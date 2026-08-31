<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Link indisponível</title>
    @include('service-orders.partials.public-styles')
</head>
<body>
    <div class="page-shell">
        <section class="message-card">
            <div class="message-icon">!</div>
            <p class="eyebrow">Link indisponível</p>
            <h1>Não foi possível acessar este documento</h1>
            <p class="muted">{{ $message }} Solicite à empresa um novo link de assinatura.</p>
        </section>
    </div>
</body>
</html>
