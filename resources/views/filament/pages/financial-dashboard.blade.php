<x-filament-panels::page>
    @php
        $dashboard = $this->dashboard;
    @endphp
    <div class="space-y-6" style="display: grid; gap: 1.5rem;">
        <x-filament::section heading="Previsão de recebimentos e pagamentos">
            <div class="flex flex-wrap gap-2" style="row-gap: 1rem;" role="group" aria-label="Período">
                @foreach(\App\Services\Financial\FinancialDashboardService::PERIODS as $value => $label)
                    <x-filament::button
                        :color="$period === $value ? 'primary' : 'gray'"
                        wire:click="$set('period', '{{ $value }}')"
                        :outlined="$period !== $value"
                    >{{ $label }}</x-filament::button>
                @endforeach
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400" style="margin-top: 1.5rem;">
                Os períodos de 7 e 30 dias incluem hoje. Só é vencida a parcela com vencimento anterior a hoje e saldo em aberto.
                Os totais consideram o período completo; a busca filtra apenas as listagens.
            </p>
        </x-filament::section>

        <div class="grid gap-4 md:grid-cols-3">
            @foreach(['receivable' => 'A receber', 'payable' => 'A pagar'] as $type => $label)
                @isset($dashboard[$type])
                    <x-filament::section :heading="$label">
                        <p class="text-2xl font-semibold">R$ {{ number_format($dashboard[$type]['total'], 2, ',', '.') }}</p>
                        <p class="mt-2 text-sm text-gray-500">{{ \App\Services\Financial\FinancialDashboardService::PERIODS[$period] }}</p>
                    </x-filament::section>
                @endisset
            @endforeach
            @if(isset($dashboard['receivable'], $dashboard['payable']))
                @php
                    $balance = $dashboard['receivable']['total'] - $dashboard['payable']['total'];
                @endphp
                <x-filament::section heading="Saldo previsto">
                    <p @class(['text-2xl font-semibold', 'text-danger-600' => $balance < 0, 'text-success-600' => $balance >= 0])>R$ {{ number_format($balance, 2, ',', '.') }}</p>
                    <p class="mt-2 text-sm text-gray-500">A receber − a pagar. Não representa saldo bancário.</p>
                </x-filament::section>
            @endif
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            @foreach(['receivable' => 'Recebimentos vencidos', 'payable' => 'Pagamentos vencidos'] as $type => $label)
                @isset($dashboard[$type])
                    <x-filament::section :heading="$label">
                        <p class="text-2xl font-semibold text-danger-600">R$ {{ number_format($dashboard[$type]['overdue'], 2, ',', '.') }}</p>
                        <x-filament::link wire:click="$set('period', 'overdue')" tag="button" class="mt-2">Relacionar vencidos</x-filament::link>
                    </x-filament::section>
                @endisset
            @endforeach
        </div>

        <div>
            <label for="financial-dashboard-search" class="mb-2 block text-sm font-medium">Buscar cliente, fornecedor, documento ou descrição</label>
            <x-filament::input.wrapper>
                <x-filament::input id="financial-dashboard-search" type="search" wire:model.live.debounce.350ms="search" placeholder="Buscar nas listagens…" />
            </x-filament::input.wrapper>
        </div>

        @foreach(['receivable' => 'Contas a receber', 'payable' => 'Contas a pagar'] as $type => $label)
            @isset($dashboard[$type])
                <x-filament::section :heading="$label . ' · ' . \App\Services\Financial\FinancialDashboardService::PERIODS[$period]">
                    <div class="overflow-x-auto">
                        <table class="w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                            <thead>
                                <tr class="text-left">
                                    <th class="px-3 py-3">{{ $type === 'receivable' ? 'Cliente' : 'Fornecedor' }}</th>
                                    <th class="px-3 py-3">Documento / parcela</th>
                                    <th class="px-3 py-3">Descrição</th>
                                    <th class="px-3 py-3">Vencimento</th>
                                    <th class="px-3 py-3">Status</th>
                                    <th class="px-3 py-3">Situação do prazo</th>
                                    <th class="px-3 py-3 text-right">Saldo em aberto</th>
                                    <th class="px-3 py-3"><span class="sr-only">Ações</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                @forelse($dashboard[$type]['rows'] as $row)
                                    @php
                                        $account = $type === 'receivable' ? $row->accountReceivable : $row->accountPayable;
                                        $overdue = $row->due_date->lt(today());
                                        $status = $row->status;
                                        // A stale overdue status must not label today's/future installments as overdue.
                                        $statusLabel = $status->value === 'overdue' ? 'Pendente' : $status->description();
                                        $statusColor = $status->value === 'overdue' ? 'warning' : $status->color();
                                        $url = $this->accountUrl($account, $type);
                                    @endphp
                                    <tr wire:key="{{ $type }}-{{ $row->id }}">
                                        <td class="px-3 py-3">{{ $account->counterparty_label }}</td>
                                        <td class="px-3 py-3">{{ $account->document_number ?: 'Sem documento' }} / {{ $row->sequence_number }}</td>
                                        <td class="px-3 py-3">{{ $row->description ?: $account->description ?: '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-3">{{ $row->due_date->format('d/m/Y') }}</td>
                                        <td class="px-3 py-3"><x-filament::badge :color="$statusColor">{{ $statusLabel }}</x-filament::badge></td>
                                        <td class="whitespace-nowrap px-3 py-3">
                                            <x-filament::badge :color="$overdue ? 'danger' : ($row->due_date->isToday() ? 'warning' : 'gray')">
                                                {{ $overdue ? 'Vencido há ' . (int) $row->due_date->diffInDays(today()) . ' dia(s)' : ($row->due_date->isToday() ? 'Vence hoje' : 'A vencer') }}
                                            </x-filament::badge>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-3 text-right font-semibold">R$ {{ number_format($row->balance_amount, 2, ',', '.') }}</td>
                                        <td class="px-3 py-3">@if($url)<x-filament::link :href="$url">Abrir conta</x-filament::link>@endif</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="px-3 py-6 text-center text-gray-500">Nenhuma parcela em aberto encontrada neste período.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $dashboard[$type]['rows']->links() }}</div>
                </x-filament::section>
            @endisset
        @endforeach
    </div>
</x-filament-panels::page>
