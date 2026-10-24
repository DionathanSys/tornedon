<x-filament-panels::page>
    @php
        $dashboard = $this->dashboard;
        $periodLabel = \App\Services\Financial\FinancialDashboardService::PERIODS[$period];
    @endphp

    @include('filament.pages.partials.financial-dashboard-styles')

    <div class="financial-dashboard">
        <x-filament::section heading="Previsão de recebimentos e pagamentos">
            <div class="financial-dashboard__periods" role="group" aria-label="Período">
                @foreach(\App\Services\Financial\FinancialDashboardService::PERIODS as $value => $label)
                    <x-filament::button
                        :color="$period === $value ? 'primary' : 'gray'"
                        wire:click="$set('period', '{{ $value }}')"
                        :outlined="$period !== $value"
                        :aria-pressed="$period === $value ? 'true' : 'false'"
                    >{{ $label }}</x-filament::button>
                @endforeach
            </div>
            <p class="financial-dashboard__period-help">
                Os períodos de 7 e 30 dias incluem hoje. Só é vencida a parcela com vencimento anterior a hoje e saldo em aberto.
                Os totais consideram o período completo; a busca filtra apenas as listagens.
            </p>
        </x-filament::section>

        @if(isset($dashboard['receivable'], $dashboard['payable']))
            @php
                $balance = $dashboard['receivable']['total'] - $dashboard['payable']['total'];
            @endphp
            <x-filament::section heading="Saldo previsto">
                <p @class(['financial-dashboard__amount', 'financial-dashboard__amount--danger' => $balance < 0, 'financial-dashboard__amount--success' => $balance >= 0])>
                    R$ {{ number_format($balance, 2, ',', '.') }}
                </p>
                <p class="financial-dashboard__muted">{{ $periodLabel }} · A receber − a pagar. Não representa saldo bancário.</p>
            </x-filament::section>
        @endif

        <div>
            <label for="financial-dashboard-search" class="financial-dashboard__search-label">Buscar cliente, fornecedor, documento ou descrição</label>
            <x-filament::input.wrapper>
                <x-filament::input id="financial-dashboard-search" type="search" wire:model.live.debounce.350ms="search" placeholder="Buscar nas listagens…" />
            </x-filament::input.wrapper>
        </div>

        <div @class(['financial-dashboard__columns', 'financial-dashboard__columns--single' => count($dashboard) === 1])>
            @foreach(['payable' => 'Contas a pagar', 'receivable' => 'Contas a receber'] as $type => $label)
                @isset($dashboard[$type])
                    <div class="financial-dashboard__column" data-account-type="{{ $type }}">
                        <x-filament::section :heading="$label">
                            <div class="financial-dashboard__summary">
                                <div>
                                    <p class="financial-dashboard__muted">{{ $periodLabel }}</p>
                                    <p @class(['financial-dashboard__amount', 'financial-dashboard__amount--danger' => $type === 'payable', 'financial-dashboard__amount--success' => $type === 'receivable'])>
                                        R$ {{ number_format($dashboard[$type]['total'], 2, ',', '.') }}
                                    </p>
                                    <p class="financial-dashboard__muted">Saldo em aberto no período</p>
                                </div>
                                <div class="financial-dashboard__overdue">
                                    <p class="financial-dashboard__muted">{{ $type === 'payable' ? 'Pagamentos vencidos' : 'Recebimentos vencidos' }}</p>
                                    <p class="financial-dashboard__overdue-amount">R$ {{ number_format($dashboard[$type]['overdue'], 2, ',', '.') }}</p>
                                    <x-filament::link wire:click="$set('period', 'overdue')" tag="button">Relacionar vencidos</x-filament::link>
                                </div>
                            </div>
                        </x-filament::section>

                        <x-filament::section :heading="'Parcelas · ' . $periodLabel">
                            <div class="financial-dashboard__list" role="list" aria-label="{{ $label }}">
                                @forelse($dashboard[$type]['rows'] as $row)
                                    @php
                                        $account = $type === 'receivable' ? $row->accountReceivable : $row->accountPayable;
                                    @endphp
                                    <x-financial.installment-list-item
                                        :installment="$row"
                                        :type="$type"
                                        :url="$this->accountUrl($account, $type)"
                                    />
                                @empty
                                    <div class="financial-dashboard__empty">
                                        <x-mary-icon name="o-inbox" class="financial-dashboard__empty-icon" />
                                        <p>Nenhuma parcela em aberto encontrada neste período.</p>
                                    </div>
                                @endforelse
                            </div>
                            @if($dashboard[$type]['rows']->hasPages())
                                <div class="financial-dashboard__pagination">{{ $dashboard[$type]['rows']->links() }}</div>
                            @endif
                        </x-filament::section>
                    </div>
                @endisset
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
