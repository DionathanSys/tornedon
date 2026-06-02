<x-filament-panels::page>
    <div class="space-y-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach ($this->stats as $stat)
                <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm">
                    <p class="text-sm font-medium text-zinc-500">{{ $stat['label'] }}</p>
                    <p class="mt-2 text-2xl font-semibold {{ $stat['color'] }}">{{ $stat['value'] }}</p>
                    <p class="mt-1 text-xs text-zinc-500">{{ $stat['description'] }}</p>
                </section>
            @endforeach
        </div>

        <section class="rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <header class="border-b border-zinc-100 px-4 py-3">
                <h2 class="text-base font-semibold text-zinc-900">Ordens de hoje</h2>
                <p class="text-sm text-zinc-500">Toque em uma ordem para abrir a edição.</p>
            </header>

            @if (count($this->orders) === 0)
                <div class="px-4 py-6 text-sm text-zinc-500">
                    Nenhuma ordem de serviço foi criada para hoje.
                </div>
            @else
                <div class="divide-y divide-zinc-100">
                    @foreach ($this->orders as $order)
                        <a
                            href="{{ $order['edit_url'] }}"
                            class="block px-4 py-4 transition hover:bg-zinc-50 focus:outline-none focus:ring-2 focus:ring-zinc-300"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-semibold text-zinc-900">{{ $order['number'] }}</span>
                                        <span @class([
                                            'inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium',
                                            'bg-sky-100 text-sky-700' => $order['status_color'] === 'info',
                                            'bg-emerald-100 text-emerald-700' => $order['status_color'] === 'success',
                                            'bg-amber-100 text-amber-700' => $order['status_color'] === 'warning',
                                            'bg-rose-100 text-rose-700' => $order['status_color'] === 'danger',
                                            'bg-zinc-100 text-zinc-700' => ! in_array($order['status_color'], ['info', 'success', 'warning', 'danger'], true),
                                        ])>
                                            {{ $order['status'] }}
                                        </span>
                                    </div>
                                    <p class="mt-1 truncate text-sm text-zinc-700">{{ $order['customer'] }}</p>
                                    <p class="mt-1 text-xs text-zinc-500">{{ $order['technician'] }}</p>
                                </div>

                                <div class="text-right">
                                    <p class="text-sm font-semibold text-zinc-900">{{ $order['total'] }}</p>
                                    <p class="mt-1 text-xs text-zinc-500">{{ $order['order_date'] }}</p>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
