<div class="bpo-page bpo-account-detail">
    @php($account = $this->accountDetails())
    <header class="bpo-page-heading">
        <div><span class="bpo-eyebrow">{{ $account['payable'] ? 'DESPESA' : 'RECEITA' }} · LANÇAMENTO #{{ $account['id'] }}</span><h1>{{ $account['title'] }}</h1><p>Dados, documentos e movimentações financeiras em uma única visão.</p></div>
        <div class="bpo-account-actions"><a class="bpo-button is-secondary" href="{{ $account['back_url'] }}"><x-filament::icon icon="heroicon-o-arrow-left" />Voltar à lista</a>@if ($this->editAccountAction->isVisible()){{ $this->editAccountAction }}@endif</div>
    </header>

    <div class="bpo-account-top-grid">
        <section class="bpo-workbench bpo-account-summary" aria-label="Cabeçalho do lançamento">
            <header class="bpo-workbench-header"><div><span class="bpo-eyebrow">{{ $account['partner_label'] }}</span><h2>{{ $account['partner'] }}</h2><p>{{ $account['partner_document'] ?: 'Contraparte deste lançamento' }}</p></div><div class="bpo-account-status"><x-filament::badge :color="$account['status_color']">{{ $account['status'] }}</x-filament::badge>@if ($account['consideration'])<span class="bpo-badge">{{ $account['consideration'] }}</span>@endif</div></header>
            <div class="bpo-account-amounts">
                @foreach ([['label' => 'Valor atualizado', 'key' => 'total'], ['label' => $account['payable'] ? 'Total pago' : 'Total recebido', 'key' => 'settled'], ['label' => 'Saldo considerado', 'key' => 'balance']] as $amount)
                    <div class="{{ $amount['key'] === 'balance' ? 'is-balance' : '' }}"><span>{{ $amount['label'] }}</span><strong>R$ {{ number_format($account[$amount['key']], 2, ',', '.') }}</strong></div>
                @endforeach
            </div>
            <dl class="bpo-account-fields">
                <div><dt>Número do documento</dt><dd>{{ $account['document'] }}</dd></div>
                <div><dt>Próximo vencimento em aberto</dt><dd>{{ $account['next_due'] }}</dd></div>
                <div><dt>Forma de pagamento</dt><dd>{{ $account['payment_method'] }}</dd></div>
                <div><dt>Categoria financeira</dt><dd>{{ $account['category'] }}</dd></div>
                <div><dt>Centro de custo</dt><dd>{{ $account['cost_center'] }}</dd></div>
                <div><dt>Centro de resultado</dt><dd>{{ $account['result_center'] }}</dd></div>
            </dl>
            <div class="bpo-account-description"><span>Descrição do lançamento</span><p>{{ $account['description'] }}</p></div>
            <footer class="bpo-workbench-footer"><x-filament::icon icon="heroicon-o-information-circle" /><span>O saldo considerado exclui parcelas ignoradas ou canceladas. Criado em {{ $account['created_at'] }}.</span></footer>
        </section>

        <aside class="bpo-account-attachments" aria-label="Anexos do lançamento">
            @livewire(\App\Livewire\Bpo\RecordAttachments::class, ['module' => $account['module'], 'recordId' => $account['id']], key('account-attachments-'.$account['module'].'-'.$account['id']))
        </aside>
    </div>

    <section class="bpo-account-relationships" aria-label="Parcelas e {{ $account['payable'] ? 'pagamentos' : 'recebimentos' }}">
        <header class="bpo-account-relationship-heading"><div><h2>Parcelas e {{ $account['payable'] ? 'pagamentos' : 'recebimentos' }}</h2><p>Vencimentos e histórico de baixas lado a lado, com ações conforme suas permissões.</p></div><span class="bpo-badge">{{ $account['installments_count'] }} {{ $account['installments_count'] === 1 ? 'parcela' : 'parcelas' }} · {{ $account['payments_count'] }} {{ $account['payable'] ? ($account['payments_count'] === 1 ? 'pagamento' : 'pagamentos') : ($account['payments_count'] === 1 ? 'recebimento' : 'recebimentos') }}</span></header>
        <div class="bpo-account-relationship-grid">
            <div class="bpo-account-relationship-panel" aria-label="Parcelas do lançamento">@livewire($account['installments_manager'], ['ownerRecord' => $this->getRecord(), 'pageClass' => $this::class], key('account-installments-'.$account['module'].'-'.$account['id']))</div>
            <div class="bpo-account-relationship-panel" aria-label="Histórico de baixas">@livewire($account['payments_manager'], ['ownerRecord' => $this->getRecord(), 'pageClass' => $this::class], key('account-payments-'.$account['module'].'-'.$account['id']))</div>
        </div>
    </section>
    <x-filament-actions::modals />
</div>
