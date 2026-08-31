<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Assinatura da Ordem de Serviço</title>
    @include('service-orders.partials.public-styles')
</head>
<body>
    <div class="page-shell">
        <header class="public-header">
            <div class="brand">
                @if (filled($pdfData['company_logo']))
                    <img src="{{ $pdfData['company_logo'] }}" alt="Logo de {{ $pdfData['company_name'] }}">
                @endif
                <div>
                    <p class="eyebrow">Documento para conferência</p>
                    <h1>Assinatura da ordem de serviço</h1>
                    <p class="muted small">Confira os dados abaixo antes de assinar.</p>
                </div>
            </div>
            <div class="header-side">
                <p class="order-title">{{ $pdfData['title'] }}</p>
                <p class="muted small">Link válido até {{ $link->expires_at->format('d/m/Y H:i') }}</p>
            </div>
        </header>

        @if ($errors->any())
            <div class="alert" role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="content-grid">
            <main class="stack">
                <section class="section">
                    <h2>Informações da ordem</h2>
                    <div class="meta-grid">
                        @foreach (array_merge($pdfData['header_lines'], [['label' => 'Data da ordem', 'value' => $pdfData['order_date']]], $pdfData['responsibles']) as $line)
                            <div class="meta-card">
                                <div class="meta-label">{{ $line['label'] }}</div>
                                <div class="meta-value">{{ $line['value'] ?: '-' }}</div>
                                @if (filled($line['secondary_value'] ?? null))
                                    <div class="meta-secondary">{{ $line['secondary_value'] }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="section">
                    <h2>Serviços</h2>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Serviço</th>
                                    <th class="number">Qtd.</th>
                                    <th class="number">Valor unit.</th>
                                    <th class="number">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($pdfData['items'] as $item)
                                    <tr>
                                        <td>{{ $item['service'] }}</td>
                                        <td class="number">{{ $item['quantity'] }}</td>
                                        <td class="number">{{ $item['unit_price'] }}</td>
                                        <td class="number">{{ $item['total_amount'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="muted">Nenhum serviço informado.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                @if (! empty($pdfData['requisition']))
                    <section class="section">
                        <h2>{{ $pdfData['requisition']['title'] }}</h2>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Produto</th>
                                        <th>Unidade</th>
                                        <th class="number">Qtd.</th>
                                        <th class="number">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($pdfData['requisition']['items'] as $item)
                                        <tr>
                                            <td>{{ $item['product'] }}</td>
                                            <td>{{ $item['unit_of_measure'] }}</td>
                                            <td class="number">{{ $item['quantity'] }}</td>
                                            <td class="number">{{ $item['total_amount'] }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="muted">Nenhum produto vinculado.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif

                @if (filled($pdfData['customer_observations']) || filled($pdfData['solution']) || filled($pdfData['technician_observations']) || filled($pdfData['additional_info_text']))
                    <section class="section">
                        <h2>Observações</h2>
                        @if (filled($pdfData['customer_observations']))
                            <p class="notes"><strong>Observações do cliente:</strong><br>{{ $pdfData['customer_observations'] }}</p>
                        @endif
                        @if (filled($pdfData['solution']))
                            <p class="notes"><strong>Solução aplicada:</strong><br>{{ $pdfData['solution'] }}</p>
                        @endif
                        @if (filled($pdfData['technician_observations']))
                            <p class="notes"><strong>Observações do técnico:</strong><br>{{ $pdfData['technician_observations'] }}</p>
                        @endif
                        @if (filled($pdfData['additional_info_text']))
                            <p class="notes"><strong>Informações adicionais:</strong><br>{{ $pdfData['additional_info_text'] }}</p>
                        @endif
                    </section>
                @endif
            </main>

            <aside class="stack">
                <section class="section">
                    <h2>Resumo</h2>
                    <table class="summary-table">
                        <tbody>
                            @foreach ($pdfData['summary_lines'] as $line)
                                <tr @if ($line['label'] === 'Valor total') class="summary-total" @endif>
                                    <td>{{ $line['label'] }}</td>
                                    <td>{{ $line['value'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>

                <section class="section signature-panel">
                    <h2>Assine este documento</h2>
                    <p class="signature-intro muted small">Informe seu nome e faça sua assinatura. Ao confirmar, você declara que leu e está de acordo com as informações apresentadas.</p>

                    <form id="service-order-signature-form" method="post" action="{{ route('service-orders.signature.store', ['token' => $token]) }}">
                        @csrf
                        <div class="field">
                            <label for="customer_signed_by_name">Nome completo</label>
                            <input id="customer_signed_by_name" name="customer_signed_by_name" type="text" value="{{ old('customer_signed_by_name') }}" maxlength="255" autocomplete="name" required>
                            @error('customer_signed_by_name')<p class="field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="field">
                            <label for="signature-pad">Assinatura</label>
                            <div class="canvas-frame">
                                <canvas id="signature-pad" aria-label="Campo para desenhar sua assinatura"></canvas>
                            </div>
                            <p class="canvas-help">Desenhe dentro do quadro usando o dedo, mouse ou caneta.</p>
                            <div class="signature-tools">
                                <span class="muted small">Sua assinatura será anexada à ordem.</span>
                                <button id="clear-signature" class="clear-button" type="button">Limpar</button>
                            </div>
                            <input id="customer_signature" name="customer_signature" type="hidden">
                            @error('customer_signature')<p class="field-error">{{ $message }}</p>@enderror
                        </div>

                        <label class="agreement" for="agreement">
                            <input id="agreement" name="agreement" type="checkbox" value="1" @checked(old('agreement')) required>
                            <span>Confirmo que li a ordem de serviço e concordo com as informações apresentadas.</span>
                        </label>
                        @error('agreement')<p class="field-error">{{ $message }}</p>@enderror

                        <button id="submit-signature" class="submit-button" type="submit">Confirmar assinatura</button>
                    </form>
                </section>
            </aside>
        </div>
    </div>

    <script>
        (() => {
            const canvas = document.getElementById('signature-pad');
            const form = document.getElementById('service-order-signature-form');
            const hiddenInput = document.getElementById('customer_signature');
            const clearButton = document.getElementById('clear-signature');
            const context = canvas.getContext('2d');
            let drawing = false;
            let activePointerId = null;
            let hasSignature = false;

            const resizeCanvas = () => {
                const previous = hasSignature ? canvas.toDataURL('image/png') : null;
                const rect = canvas.getBoundingClientRect();
                const ratio = Math.max(window.devicePixelRatio || 1, 1);

                canvas.width = Math.max(Math.floor(rect.width * ratio), 1);
                canvas.height = Math.max(Math.floor(rect.height * ratio), 1);
                context.setTransform(ratio, 0, 0, ratio, 0, 0);
                context.lineCap = 'round';
                context.lineJoin = 'round';
                context.lineWidth = 2.2;
                context.strokeStyle = '#17212b';
                context.clearRect(0, 0, rect.width, rect.height);

                if (previous) {
                    const image = new Image();
                    image.onload = () => context.drawImage(image, 0, 0, rect.width, rect.height);
                    image.src = previous;
                }
            };

            const point = (event) => {
                const rect = canvas.getBoundingClientRect();
                return { x: event.clientX - rect.left, y: event.clientY - rect.top };
            };

            canvas.addEventListener('pointerdown', (event) => {
                event.preventDefault();
                drawing = true;
                activePointerId = event.pointerId;
                canvas.setPointerCapture?.(event.pointerId);
                const position = point(event);
                context.beginPath();
                context.moveTo(position.x, position.y);
                context.arc(position.x, position.y, 1.2, 0, Math.PI * 2);
                context.fillStyle = '#17212b';
                context.fill();
                hasSignature = true;
            });

            canvas.addEventListener('pointermove', (event) => {
                if (!drawing || event.pointerId !== activePointerId) return;
                event.preventDefault();
                const position = point(event);
                context.lineTo(position.x, position.y);
                context.stroke();
            });

            const finishDrawing = (event) => {
                if (!drawing || (event && event.pointerId !== activePointerId)) return;
                drawing = false;
                canvas.releasePointerCapture?.(activePointerId);
                activePointerId = null;
                context.closePath();
                hiddenInput.value = hasSignature ? canvas.toDataURL('image/png') : '';
            };

            canvas.addEventListener('pointerup', finishDrawing);
            canvas.addEventListener('pointercancel', finishDrawing);
            clearButton.addEventListener('click', () => {
                context.clearRect(0, 0, canvas.clientWidth, canvas.clientHeight);
                hasSignature = false;
                hiddenInput.value = '';
            });
            form.addEventListener('submit', (event) => {
                hiddenInput.value = hasSignature ? canvas.toDataURL('image/png') : '';
                if (!hasSignature) {
                    event.preventDefault();
                    alert('Faça sua assinatura no campo indicado.');
                    canvas.focus();
                    return;
                }
                document.getElementById('submit-signature').disabled = true;
            });

            resizeCanvas();
            window.addEventListener('resize', resizeCanvas);
        })();
    </script>
</body>
</html>
