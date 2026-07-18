<x-filament-panels::page>
    <style>
        .pr-mob-page { display: grid; gap: 1rem; padding-bottom: 1rem; }
        .pr-mob-new { display: inline-flex; align-items: center; justify-content: center; min-height: 3.4rem; border-radius: 1rem; background: #111827; color: #fff; font-weight: 800; text-decoration: none; }
        .pr-mob-tabs { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .45rem; }
        .pr-mob-tab { min-height: 3.3rem; border: 0; border-radius: .9rem; background: #e2e8f0; color: #334155; font-size: .75rem; font-weight: 800; }
        .pr-mob-tab span { display: block; margin-top: .2rem; font-size: .7rem; opacity: .8; }
        .pr-mob-tab.is-active { background: #111827; color: #fff; }
        .pr-mob-filters { display: grid; grid-template-columns: 1fr 1fr auto; gap: .5rem; align-items: end; border-radius: 1rem; padding: .75rem; background: #fff; box-shadow: 0 16px 40px -34px rgba(15, 23, 42, .22); }
        .pr-mob-filter { display: grid; gap: .3rem; }
        .pr-mob-filter label { color: #64748b; font-size: .65rem; font-weight: 800; text-transform: uppercase; }
        .pr-mob-filter input { width: 100%; min-height: 2.65rem; border: 1px solid rgba(148, 163, 184, .45); border-radius: .8rem; padding: .45rem .6rem; background: #fff; color: #0f172a; }
        .pr-mob-filter-clear { min-height: 2.65rem; border: 0; border-radius: .8rem; padding: 0 .7rem; background: #e2e8f0; color: #334155; font-size: .72rem; font-weight: 850; }
        .pr-mob-list { display: grid; gap: .75rem; }
        .pr-mob-card { display: grid; gap: .75rem; border: 1px solid rgba(148, 163, 184, .25); border-radius: 1rem; padding: .95rem; background: #fff; color: #0f172a; text-decoration: none; box-shadow: 0 16px 40px -34px rgba(15, 23, 42, .22); }
        .pr-mob-card__top { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
        .pr-mob-card__title { margin: 0; font-size: .98rem; font-weight: 800; }
        .pr-mob-card__sub { margin: .25rem 0 0; color: #64748b; font-size: .76rem; }
        .pr-mob-badge { white-space: nowrap; border-radius: 999px; padding: .25rem .55rem; background: #fef3c7; color: #92400e; font-size: .7rem; font-weight: 800; }
        .pr-mob-badge--success { background: #dcfce7; color: #166534; }
        .pr-mob-badge--danger { background: #fee2e2; color: #b91c1c; }
        .pr-mob-badge--info { background: #dbeafe; color: #1d4ed8; }
        .pr-mob-badge--gray { background: #e5e7eb; color: #374151; }
        .pr-mob-meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
        .pr-mob-meta div { border-radius: .75rem; padding: .55rem .65rem; background: #f8fafc; }
        .pr-mob-meta span { display: block; color: #64748b; font-size: .65rem; font-weight: 800; text-transform: uppercase; }
        .pr-mob-meta strong { display: block; margin-top: .2rem; font-size: .82rem; }
        .pr-mob-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
        .pr-mob-action { display: inline-flex; align-items: center; justify-content: center; min-height: 2.65rem; border: 0; border-radius: .85rem; background: #111827; color: #fff; font-size: .78rem; font-weight: 800; text-decoration: none; }
        .pr-mob-action--secondary { background: #e2e8f0; color: #334155; }
        .pr-mob-action:disabled { opacity: .5; }
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
        .pr-mob-empty { border-radius: 1rem; padding: 1rem; background: #fff; color: #64748b; text-align: center; }
    </style>

    <div class="pr-mob-page">
        <a href="{{ $this->getCreateUrl() }}" class="pr-mob-new">Novo Contas à Receber</a>

        <div class="pr-mob-tabs">
            <button type="button" wire:click="setTab('pending')" class="pr-mob-tab @if ($activeTab === 'pending') is-active @endif">Pendentes<span>{{ $this->pendingCount }}</span></button>
            <button type="button" wire:click="setTab('{{ \App\Enum\AccountReceivable\Status::RECEIVED->value }}')" class="pr-mob-tab @if ($activeTab === \App\Enum\AccountReceivable\Status::RECEIVED->value) is-active @endif">Recebidas<span>{{ $this->receivedCount }}</span></button>
            <button type="button" wire:click="setTab('all')" class="pr-mob-tab @if ($activeTab === 'all') is-active @endif">Todas<span>{{ $this->allCount }}</span></button>
        </div>

        <div class="pr-mob-filters">
            <div class="pr-mob-filter"><label>De</label><input type="date" wire:model.live="dateFrom"></div>
            <div class="pr-mob-filter"><label>Até</label><input type="date" wire:model.live="dateTo"></div>
            <button type="button" class="pr-mob-filter-clear" wire:click="clearDateFilters">Limpar</button>
        </div>

        <div class="pr-mob-list">
            @forelse ($this->accountReceivables as $receivable)
                <div class="pr-mob-card">
                    <div class="pr-mob-card__top">
                        <div>
                            <p class="pr-mob-card__title">{{ $receivable->counterparty_label }}</p>
                            <p class="pr-mob-card__sub">{{ $receivable->document_number ?: 'Sem documento' }} - {{ $receivable->due_date?->format('d/m/Y') ?? '-' }}</p>
                        </div>

                        <span @class([
                            'pr-mob-badge',
                            'pr-mob-badge--success' => $receivable->status === \App\Enum\AccountReceivable\Status::RECEIVED,
                            'pr-mob-badge--danger' => $receivable->status === \App\Enum\AccountReceivable\Status::OVERDUE,
                            'pr-mob-badge--info' => $receivable->status === \App\Enum\AccountReceivable\Status::PARTIALLY_RECEIVED,
                            'pr-mob-badge--gray' => $receivable->status === \App\Enum\AccountReceivable\Status::CANCELLED,
                        ])>{{ $receivable->status === \App\Enum\AccountReceivable\Status::OVERDUE ? 'Pendente' : $receivable->status->description() }}</span>
                    </div>

                    <div class="pr-mob-meta">
                        <div><span>Valor</span><strong>R$ {{ number_format((float) $receivable->due_amount, 2, ',', '.') }}</strong></div>
                        <div><span>Recebido</span><strong>R$ {{ number_format((float) $receivable->paid_amount, 2, ',', '.') }}</strong></div>
                    </div>

                    <div class="pr-mob-actions">
                        <a href="{{ $this->getDetailUrl($receivable) }}" class="pr-mob-action pr-mob-action--secondary">Ver detalhes</a>
                        @if (in_array($receivable->status, [\App\Enum\AccountReceivable\Status::PENDING, \App\Enum\AccountReceivable\Status::PARTIALLY_RECEIVED, \App\Enum\AccountReceivable\Status::OVERDUE], true))
                            <button
                                type="button"
                                class="pr-mob-action"
                                wire:click="openRegisterPayment({{ $receivable->getKey() }})"
                                wire:loading.attr="disabled"
                            >
                                Pago?
                            </button>
                        @endif
                    </div>

                    @if ($showPaymentForm && $paymentReceivableId === $receivable->getKey())
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
                                <button type="button" wire:click="savePayment" class="pr-mob-save">Salvar</button>
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="pr-mob-empty">Nenhuma conta a receber encontrada.</div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
