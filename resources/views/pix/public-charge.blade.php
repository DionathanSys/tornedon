<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Pagamento PIX</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #172033;
            background: #eef3f8;
        }

        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; }
        .shell { width: min(100% - 32px, 760px); margin: 0 auto; padding: 32px 0; }
        .card { background: #ffffff; border: 1px solid #dbe3ed; border-radius: 22px; box-shadow: 0 18px 45px rgba(28, 47, 75, .09); padding: 28px; }
        .eyebrow { color: #087f5b; font-size: 12px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; margin: 0 0 8px; }
        h1 { font-size: clamp(24px, 5vw, 34px); line-height: 1.1; margin: 0; }
        h2 { font-size: 18px; margin: 0 0 12px; }
        .muted { color: #607089; line-height: 1.55; }
        .payment-grid { display: grid; gap: 18px; grid-template-columns: minmax(220px, .9fr) minmax(260px, 1.1fr); margin-top: 28px; }
        .panel { background: #f7fafc; border: 1px solid #e2e9f1; border-radius: 16px; padding: 20px; }
        .qr { display: block; width: min(100%, 280px); aspect-ratio: 1; margin: 0 auto 12px; border-radius: 10px; background: #fff; }
        .copy { width: 100%; min-height: 132px; resize: vertical; border: 1px solid #c9d4e1; border-radius: 10px; padding: 12px; color: #172033; background: #fff; font: 13px/1.45 ui-monospace, SFMono-Regular, Menlo, monospace; }
        button { width: 100%; border: 0; border-radius: 10px; padding: 12px 16px; margin-top: 10px; color: #fff; background: #087f5b; cursor: pointer; font: inherit; font-weight: 700; }
        button:hover { background: #066b4d; }
        .summary { display: flex; flex-wrap: wrap; gap: 10px 28px; margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e9f1; }
        .summary strong { display: block; font-size: 18px; margin-top: 4px; }
        .status { margin-top: 24px; border-radius: 14px; padding: 18px; background: #fff8e6; border: 1px solid #f1d795; }
        .status strong { display: block; margin-bottom: 6px; }
        @media (max-width: 640px) {
            .shell { width: min(100% - 20px, 760px); padding: 10px 0; }
            .card { padding: 20px 16px; border-radius: 16px; }
            .payment-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="card">
            <p class="eyebrow">Pagamento PIX</p>
            <h1>{{ $companyName }}</h1>

            @if ($isPayable)
                <p class="muted">Escaneie o QR Code no aplicativo do seu banco ou copie o código PIX.</p>

                <div class="payment-grid">
                    <section class="panel">
                        <h2>QR Code</h2>
                        @if ($qrCodeDataUri)
                            <img class="qr" src="{{ $qrCodeDataUri }}" alt="QR Code para pagamento PIX">
                        @else
                            <p class="muted">QR Code indisponível.</p>
                        @endif
                        <p class="muted">Aponte a câmera do aplicativo do seu banco para o código acima.</p>
                    </section>

                    <section class="panel">
                        <h2>Copia e cola</h2>
                        <textarea id="pix-copy-paste" class="copy" readonly>{{ $pixCopyPaste }}</textarea>
                        <button type="button" onclick="copyPixCode(this)">Copiar código PIX</button>
                    </section>
                </div>

                <div class="summary">
                    <div>
                        <span class="muted">Valor</span>
                        <strong>R$ {{ number_format((float) $charge->amount, 2, ',', '.') }}</strong>
                    </div>
                    <div>
                        <span class="muted">Vencimento</span>
                        <strong>{{ $charge->due_date?->format('d/m/Y') ?? '-' }}</strong>
                    </div>
                    @if ($linkExpiresAt)
                        <div>
                            <span class="muted">Link válido até</span>
                            <strong>{{ $linkExpiresAt->format('d/m/Y H:i') }}</strong>
                        </div>
                    @endif
                </div>
            @else
                <div class="status">
                    <strong>{{ $statusLabel }}</strong>
                    <span>{{ $statusMessage }}</span>
                </div>
            @endif
        </section>
    </main>

    @if ($isPayable && filled($pixCopyPaste))
        <script>
            function copyPixCode(button) {
                const field = document.getElementById('pix-copy-paste');

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(field.value).then(() => {
                        button.textContent = 'Código copiado';
                    });
                    return;
                }

                field.select();
                document.execCommand('copy');
                button.textContent = 'Código copiado';
            }
        </script>
    @endif
</body>
</html>
