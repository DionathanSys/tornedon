<x-filament-panels::page>
    <div class="space-y-4">
        <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Data de referencia</h2>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Use a data abaixo para atualizar os indicadores e a lista.</p>
                </div>

                <label class="block">
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Data</span>
                    <input
                        type="date"
                        wire:model.live="selectedDate"
                        class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-400 focus:outline-none focus:ring-2 focus:ring-zinc-200 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-zinc-500 dark:focus:ring-zinc-800 sm:w-52"
                    >
                </label>
            </div>

            <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-400">Exibindo resultados para <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $this->getSelectedDateLabel() }}</span>.</p>
        </section>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach ($this->stats as $stat)
                <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ $stat['label'] }}</p>
                    <p class="mt-2 text-2xl font-semibold {{ $stat['color'] }}">{{ $stat['value'] }}</p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $stat['description'] }}</p>
                </section>
            @endforeach
        </div>

        <section class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <header class="border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Ordens encontradas</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Toque em uma ordem para abrir a edicao.</p>
            </header>

            @if (count($this->orders) === 0)
                <div class="px-4 py-6 text-sm text-zinc-500 dark:text-zinc-400">
                    Nenhuma ordem de servico foi encontrada para a data selecionada.
                </div>
            @else
                <div class="space-y-3 p-3">
                    @foreach ($this->orders as $order)
                        <a
                            href="{{ $order['edit_url'] }}"
                            class="block rounded-2xl border border-zinc-200 bg-zinc-50/60 px-[19px] py-[19px] shadow-sm transition hover:border-zinc-300 hover:bg-white focus:outline-none focus:ring-2 focus:ring-zinc-300 dark:border-zinc-700 dark:bg-zinc-950 dark:hover:border-zinc-600 dark:hover:bg-zinc-900 dark:focus:ring-zinc-700"
                        >
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0 space-y-4">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <span class="inline-flex rounded-xl bg-sky-100 px-4 py-2 text-sm font-semibold text-sky-800 shadow-sm dark:bg-sky-500/15 dark:text-sky-300">
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
                                            'dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => ! in_array($order['status_color'], ['info', 'success', 'warning', 'danger'], true),
                                        ])>
                                            {{ $order['status'] }}
                                        </span>
                                    </div>

                                    <p class="truncate text-base font-medium leading-6 text-zinc-800 dark:text-zinc-100">{{ $order['customer'] }}</p>
                                </div>

                                <div class="border-t border-zinc-200 pt-4 text-left dark:border-zinc-700 sm:min-w-32 sm:border-t-0 sm:border-l sm:border-zinc-200 sm:pl-5 sm:pt-0 sm:text-right">
                                    <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Total</p>
                                    <p class="mt-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $order['total'] }}</p>
                                    <p class="mt-3 text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Data</p>
                                    <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">{{ $order['order_date'] }}</p>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
