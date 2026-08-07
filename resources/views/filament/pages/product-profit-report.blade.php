<x-filament-panels::page>
    @php($summary = $this->summary)
    @php($rows = $this->rows)

    <div class="space-y-6">
        <form wire:submit="applyFilters" class="space-y-4">
            {{ $this->form }}

            <div class="flex flex-wrap gap-2">
                <x-filament::button type="submit" wire:loading.attr="disabled">
                    Aplicar filtros
                </x-filament::button>

                <x-filament::button type="button" color="gray" wire:click="resetFilters" wire:loading.attr="disabled">
                    Limpar filtros
                </x-filament::button>
            </div>
        </form>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                <div class="text-sm text-gray-500 dark:text-gray-400">Valor vendido</div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $this->money($summary['sold_amount']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Bruto: {{ $this->money($summary['gross_amount']) }}</div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                <div class="text-sm text-gray-500 dark:text-gray-400">Custo dos produtos</div>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $this->money($summary['cost_amount']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Com base no custo salvo no item</div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                <div class="text-sm text-gray-500 dark:text-gray-400">Lucro bruto</div>
                <div @class([
                    'mt-1 text-2xl font-semibold',
                    'text-success-600 dark:text-success-400' => $summary['profit_amount'] >= 0,
                    'text-danger-600 dark:text-danger-400' => $summary['profit_amount'] < 0,
                ])>{{ $this->money($summary['profit_amount']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Venda líquida menos custo</div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                <div class="text-sm text-gray-500 dark:text-gray-400">Margem</div>
                <div @class([
                    'mt-1 text-2xl font-semibold',
                    'text-success-600 dark:text-success-400' => $summary['margin_percent'] >= 0,
                    'text-danger-600 dark:text-danger-400' => $summary['margin_percent'] < 0,
                ])>{{ $this->decimal($summary['margin_percent']) }}%</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $summary['products_count'] }} produto(s), {{ $summary['sales_count'] }} venda(s)</div>
            </div>
        </div>

        @if($summary['missing_cost_items'] > 0)
            <div class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-200">
                {{ $summary['missing_cost_items'] }} item(ns) no período estão sem custo unitário. O custo desses itens foi considerado como zero no cálculo.
            </div>
        @endif

        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
            <div class="border-b border-gray-200 px-4 py-3 dark:border-white/10">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Margem por produto</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Agrupamento por produto e unidade de venda.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Produto</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Un.</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Qtde.</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Vendas</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Valor vendido</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Custo</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Lucro</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Margem</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Sem custo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse($rows as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">
                                    <div class="font-medium">{{ $row['product_name'] }}</div>
                                    @if($row['product_code'] !== '')
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['product_code'] }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-center text-sm text-gray-700 dark:text-gray-300">{{ $row['unit_of_measure'] }}</td>
                                <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300">{{ $this->decimal($row['quantity'], 3) }}</td>
                                <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300">{{ number_format($row['sales_count'], 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300">{{ $this->money($row['sold_amount']) }}</td>
                                <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300">{{ $this->money($row['cost_amount']) }}</td>
                                <td @class([
                                    'px-4 py-3 text-right text-sm font-medium',
                                    'text-success-600 dark:text-success-400' => $row['profit_amount'] >= 0,
                                    'text-danger-600 dark:text-danger-400' => $row['profit_amount'] < 0,
                                ])>{{ $this->money($row['profit_amount']) }}</td>
                                <td @class([
                                    'px-4 py-3 text-right text-sm font-medium',
                                    'text-success-600 dark:text-success-400' => $row['margin_percent'] >= 0,
                                    'text-danger-600 dark:text-danger-400' => $row['margin_percent'] < 0,
                                ])>{{ $this->decimal($row['margin_percent']) }}%</td>
                                <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300">{{ number_format($row['missing_cost_items'], 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Nenhuma venda de produto encontrada para os filtros informados.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($rows->isNotEmpty())
                        <tfoot class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <td class="px-4 py-3 text-sm font-semibold text-gray-950 dark:text-white" colspan="2">Total</td>
                                <td class="px-4 py-3 text-right text-sm font-semibold text-gray-950 dark:text-white">{{ $this->decimal($summary['quantity'], 3) }}</td>
                                <td class="px-4 py-3 text-right text-sm font-semibold text-gray-950 dark:text-white">{{ number_format($summary['sales_count'], 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right text-sm font-semibold text-gray-950 dark:text-white">{{ $this->money($summary['sold_amount']) }}</td>
                                <td class="px-4 py-3 text-right text-sm font-semibold text-gray-950 dark:text-white">{{ $this->money($summary['cost_amount']) }}</td>
                                <td class="px-4 py-3 text-right text-sm font-semibold text-gray-950 dark:text-white">{{ $this->money($summary['profit_amount']) }}</td>
                                <td class="px-4 py-3 text-right text-sm font-semibold text-gray-950 dark:text-white">{{ $this->decimal($summary['margin_percent']) }}%</td>
                                <td class="px-4 py-3 text-right text-sm font-semibold text-gray-950 dark:text-white">{{ number_format($summary['missing_cost_items'], 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
