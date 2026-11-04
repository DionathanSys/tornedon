<section class="bpo-account-relationships" aria-label="Parcelas e {{ $account['payable'] ? 'pagamentos' : 'recebimentos' }}">
    <header class="bpo-account-relationship-heading"><div><h2>Parcelas e {{ $account['payable'] ? 'pagamentos' : 'recebimentos' }}</h2><p>Vencimentos e histórico de baixas lado a lado, com ações conforme suas permissões.</p></div><span class="bpo-badge">{{ $account['installments_count'] }} {{ $account['installments_count'] === 1 ? 'parcela' : 'parcelas' }} · {{ $account['payments_count'] }} {{ $account['payable'] ? ($account['payments_count'] === 1 ? 'pagamento' : 'pagamentos') : ($account['payments_count'] === 1 ? 'recebimento' : 'recebimentos') }}</span></header>
    <div class="bpo-account-relationship-grid">
        <div class="bpo-account-relationship-panel" aria-label="Parcelas do lançamento">@livewire($account['installments_manager'], ['ownerRecord' => $this->getRecord(), 'pageClass' => $this::class], key('account-installments-'.$account['module'].'-'.$account['id']))</div>
        <div class="bpo-account-relationship-panel" aria-label="Histórico de baixas">@livewire($account['payments_manager'], ['ownerRecord' => $this->getRecord(), 'pageClass' => $this::class], key('account-payments-'.$account['module'].'-'.$account['id']))</div>
    </div>
</section>
