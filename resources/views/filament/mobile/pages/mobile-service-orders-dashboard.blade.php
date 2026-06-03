<x-filament-panels::page>
    <div class="space-y-4">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">Data de referencia</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Use a data abaixo para atualizar os indicadores e a lista.</p>
                </div>

                <label class="block">
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Data</span>
                    <input
                        type="date"
                        wire:model.live="selectedDate"
                        class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:border-white/10 dark:bg-white/5 dark:text-white dark:focus:border-white/20 dark:focus:ring-white/10 sm:w-52"
                    >
                </label>
            </div>

            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">Exibindo resultados para <span class="font-semibold text-gray-900 dark:text-white">{{ $this->getSelectedDateLabel() }}</span>.</p>
        </section>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach ($this->stats as $stat)
                <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $stat['label'] }}</p>
                    <p class="mt-2 text-2xl font-semibold {{ $stat['color'] }}">{{ $stat['value'] }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $stat['description'] }}</p>
                </section>
            @endforeach
        </div>

        <section class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
            <header class="border-b border-gray-100 px-4 py-3 dark:border-white/10">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Ordens encontradas</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">Toque em uma ordem para abrir a edicao.</p>
            </header>

            @if (count($this->orders) === 0)
                <div class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400">
                    Nenhuma ordem de servico foi encontrada para a data selecionada.
                </div>
            @else
                <div class="space-y-3 p-3">
                    @foreach ($this->orders as $order)
                        <a
                            href="{{ $order['edit_url'] }}"
                            class="block rounded-2xl border border-gray-200 bg-gray-50 px-6 py-5 shadow-sm transition hover:border-gray-300 hover:bg-white focus:outline-none focus:ring-2 focus:ring-gray-300 dark:border-white/10 dark:bg-white/5 dark:hover:border-white/20 dark:hover:bg-white/10 dark:focus:ring-white/10"
                        >
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0 space-y-4">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                            {{ $order['number'] }}
                                        </span>
                                        <span @class([
                                            'inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold',
                                            'border-sky-200 bg-sky-100 text-sky-700' => $order['status_color'] === 'info',
                                            'border-emerald-200 bg-emerald-100 text-emerald-700' => $order['status_color'] === 'success',
                                            'border-amber-200 bg-amber-100 text-amber-700' => $order['status_color'] === 'warning',
                                            'border-rose-200 bg-rose-100 text-rose-700' => $order['status_color'] === 'danger',
                                            'border-zinc-200 bg-zinc-100 text-zinc-700' => ! in_array($order['status_color'], ['info', 'success', 'warning', 'danger'], true),
                                            'dark:border-sky-500/40 dark:bg-sky-500/10 dark:text-sky-300' => $order['status_color'] === 'info',
                                            'dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-300' => $order['status_color'] === 'success',
                                            'dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-300' => $order['status_color'] === 'warning',
                                            'dark:border-rose-500/40 dark:bg-rose-500/10 dark:text-rose-300' => $order['status_color'] === 'danger',
                                            'dark:border-white/10 dark:bg-white/10 dark:text-white' => ! in_array($order['status_color'], ['info', 'success', 'warning', 'danger'], true),
                                        ])>
                                            {{ $order['status'] }}
                                        </span>
                                    </div>

                                    <p class="text-base font-medium leading-6 text-gray-800 dark:text-white">{{ $order['customer'] }}</p>
                                </div>

                                <div class="border-t border-gray-200 pt-4 text-left dark:border-white/10 sm:min-w-32 sm:border-t-0 sm:border-l sm:border-gray-200 sm:pl-5 sm:pt-0 sm:text-right">
                                    <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Total</p>
                                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $order['total'] }}</p>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
