<x-filament-panels::page>
    <style>
        .pr-mob-detail { display: grid; gap: .85rem; padding-bottom: 6.25rem; }
        .pr-mob-card { border: 1px solid rgba(148, 163, 184, .25); border-radius: 1.1rem; padding: .95rem; background: #fff; box-shadow: 0 16px 40px -34px rgba(15, 23, 42, .22); }
        .pr-mob-head { color: #fff; background: linear-gradient(135deg, #111827, #334155); }
        .pr-mob-title { margin: 0; font-size: 1.05rem; font-weight: 850; }
        .pr-mob-sub { margin: .3rem 0 0; color: rgba(255,255,255,.78); font-size: .78rem; }
        .pr-mob-kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .45rem; margin-top: .85rem; }
        .pr-mob-kpis div { border-radius: .75rem; padding: .55rem; background: rgba(255,255,255,.1); }
        .pr-mob-kpis span { display: block; font-size: .62rem; font-weight: 800; opacity: .7; text-transform: uppercase; }
        .pr-mob-kpis strong { display: block; margin-top: .2rem; font-size: .8rem; }
        .pr-mob-section-title { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin-bottom: .75rem; }
        .pr-mob-section-title h3 { margin: 0; font-size: .95rem; font-weight: 850; }
        .pr-mob-bottom { position: fixed; right: 0; bottom: 0; left: 0; z-index: 60; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .45rem; padding: .75rem max(.75rem, env(safe-area-inset-left)) max(.75rem, env(safe-area-inset-bottom)); border-top: 1px solid rgba(148, 163, 184, .25); background: rgba(248, 250, 252, .96); backdrop-filter: blur(14px); }
        .pr-mob-bottom a, .pr-mob-bottom button { display: inline-flex; align-items: center; justify-content: center; min-height: 3rem; border: 0; border-radius: .85rem; font-size: .72rem; font-weight: 850; text-decoration: none; }
        .pr-mob-item-form { display: grid; gap: .65rem; padding: .75rem; border-radius: .9rem; background: #f8fafc; }
        .pr-mob-field { display: grid; gap: .3rem; }
        .pr-mob-field label { color: #475569; font-size: .72rem; font-weight: 800; }
        .pr-mob-field input, .pr-mob-field select, .pr-mob-field textarea { width: 100%; min-height: 2.85rem; border: 1px solid rgba(148, 163, 184, .45); border-radius: .85rem; padding: .55rem .7rem; background: #fff; color: #0f172a; }
        .pr-mob-field textarea { min-height: 4.5rem; resize: vertical; }
        .pr-mob-payment-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .55rem; }
        .pr-mob-item-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .45rem; }
        .pr-mob-item-actions button { min-height: 2.75rem; border: 0; border-radius: .75rem; font-size: .72rem; font-weight: 850; }
        .pr-mob-secondary { background: #e2e8f0; color: #334155; }
        .pr-mob-save { background: #111827; color: #fff; }
        .pr-mob-success { background: #dcfce7; color: #166534; }
        .pr-mob-danger { background: #fee2e2; color: #b91c1c; }
    </style>

    <form wire:submit="save">
        <div class="pr-mob-detail">
            <section class="pr-mob-card pr-mob-head">
                <p class="pr-mob-title">{{ $record->counterparty_label }}</p>
                <p class="pr-mob-sub">{{ $record->document_number ?: 'Sem documento' }} - {{ $record->due_date?->format('d/m/Y') ?? '-' }}</p>
                <div class="pr-mob-kpis">
                    <div><span>Status</span><strong>{{ $record->status?->description() ?? '-' }}</strong></div>
                    <div><span>Valor</span><strong>R$ {{ number_format((float) $record->due_amount, 2, ',', '.') }}</strong></div>
                    <div><span>Recebido</span><strong>R$ {{ number_format((float) $record->paid_amount, 2, ',', '.') }}</strong></div>
                </div>
            </section>

            <section class="pr-mob-card">
                <div class="pr-mob-section-title">
                    <h3>Dados da conta</h3>
                </div>

                {{ $this->form }}
            </section>

            @if ($showPaymentForm)
                <section class="pr-mob-card">
                    <div class="pr-mob-section-title">
                        <h3>Registrar pagamento</h3>
                    </div>

                    <div class="pr-mob-item-form">
                        <div class="pr-mob-payment-grid">
                            <div class="pr-mob-field">
                                <label>Data</label>
                                <input type="date" wire:model="paymentData.payment_date">
                            </div>
                            <div class="pr-mob-field">
                                <label>Valor recebido</label>
                                <input type="text" inputmode="decimal" wire:model="paymentData.amount">
                            </div>
                        </div>

                        <div class="pr-mob-payment-grid">
                            <div class="pr-mob-field">
                                <label>Juros</label>
                                <input type="text" inputmode="decimal" wire:model="paymentData.interest_amount">
                            </div>
                            <div class="pr-mob-field">
                                <label>Multa</label>
                                <input type="text" inputmode="decimal" wire:model="paymentData.fine_amount">
                            </div>
                        </div>

                        <div class="pr-mob-payment-grid">
                            <div class="pr-mob-field">
                                <label>Desconto</label>
                                <input type="text" inputmode="decimal" wire:model="paymentData.discount_amount">
                            </div>
                            <div class="pr-mob-field">
                                <label>Conta financeira</label>
                                <select wire:model="paymentData.financial_account_id">
                                    <option value="">Selecione</option>
                                    @foreach ($this->financialAccountOptions as $accountId => $accountName)
                                        <option value="{{ $accountId }}">{{ $accountName }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="pr-mob-field">
                            <label>Descrição</label>
                            <input type="text" wire:model="paymentData.description">
                        </div>

                        <div class="pr-mob-field">
                            <label>Observações</label>
                            <textarea wire:model="paymentData.notes"></textarea>
                        </div>

                        <div class="pr-mob-item-actions">
                            <button type="button" wire:click="cancelRegisterPayment" class="pr-mob-secondary">Cancelar</button>
                            <button type="button" wire:click="savePayment" class="pr-mob-save">Salvar pagamento</button>
                        </div>
                    </div>
                </section>
            @endif
        </div>

        <section class="pr-mob-bottom">
            <a href="{{ $this->getListUrl() }}" class="pr-mob-secondary">Lista</a>
            <button type="submit" class="pr-mob-save" wire:loading.attr="disabled">Salvar</button>
            @if (in_array($record->status, [\App\Enum\AccountReceivable\Status::PENDING, \App\Enum\AccountReceivable\Status::PARTIALLY_RECEIVED, \App\Enum\AccountReceivable\Status::OVERDUE], true))
                <button type="button" wire:click="openRegisterPayment" class="pr-mob-success" wire:loading.attr="disabled">Pago?</button>
            @else
                <button type="button" disabled class="pr-mob-secondary">Pago?</button>
            @endif
            <button type="button" wire:click="deleteRecord" class="pr-mob-danger" wire:confirm="Excluir esta conta a receber?">Excluir</button>
        </section>
    </form>
</x-filament-panels::page>
