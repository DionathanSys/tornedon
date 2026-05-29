<x-filament-widgets::widget>
    <div class="grid gap-4 2xl:grid-cols-3">
        <section class="relative overflow-hidden rounded-3xl border border-white/60 bg-gradient-to-br from-zinc-950 via-zinc-900 to-zinc-800 p-6 text-white shadow-[0_24px_80px_-32px_rgba(15,23,42,0.85)] ring-1 ring-black/5 dark:border-white/10 2xl:col-span-2">
            <div class="absolute inset-y-0 right-0 hidden w-2/5 bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.18),transparent_58%)] lg:block"></div>
            <div class="absolute -right-16 -top-16 hidden h-40 w-40 rounded-full bg-white/10 blur-3xl lg:block"></div>

            <div class="relative flex h-full flex-col gap-6">
                <div class="flex items-start justify-between gap-4">
                    <div class="space-y-2">
                        <span class="inline-flex items-center rounded-full border border-white/15 bg-white/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.24em] text-zinc-200">
                            Panorama Financeiro
                        </span>

                        <div>
                            <p class="text-sm text-zinc-300">Valor liquido das faturas filtradas</p>
                            <h2 class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">{{ $summary['net_value'] }}</h2>
                        </div>
                    </div>

                    <div class="hidden rounded-2xl border border-white/10 bg-white/10 px-4 py-3 text-right backdrop-blur-sm sm:block">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-zinc-300">Base atual</p>
                        <p class="mt-1 text-sm text-zinc-100">Filtros da tabela</p>
                    </div>
                </div>

                <div class="grid gap-3 md:grid-cols-3">
                    <div class="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur-sm">
                        <p class="text-xs font-medium uppercase tracking-[0.2em] text-zinc-300">Faturas</p>
                        <p class="mt-2 text-2xl font-semibold">{{ number_format($summary['total_invoices'], 0, ',', '.') }}</p>
                        <p class="mt-1 text-sm text-zinc-400">registros na visao atual</p>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur-sm">
                        <p class="text-xs font-medium uppercase tracking-[0.2em] text-zinc-300">Ticket medio</p>
                        <p class="mt-2 text-2xl font-semibold">{{ $summary['average_ticket'] }}</p>
                        <p class="mt-1 text-sm text-zinc-400">por fatura filtrada</p>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur-sm">
                        <p class="text-xs font-medium uppercase tracking-[0.2em] text-zinc-300">Composicao</p>
                        <p class="mt-2 text-2xl font-semibold">{{ $summary['services_share'] }}</p>
                        <p class="mt-1 text-sm text-zinc-400">servicos no total liquido</p>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="space-y-3 rounded-2xl border border-white/10 bg-black/10 p-4 backdrop-blur-sm">
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <span class="text-zinc-300">Servicos</span>
                            <span class="font-medium text-zinc-50">{{ $summary['services_total'] }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-white/10">
                            <div class="h-full rounded-full bg-gradient-to-r from-cyan-400 via-sky-400 to-indigo-400" style="width: {{ $summary['services_share_width'] }}"></div>
                        </div>
                        <p class="text-sm text-zinc-400">{{ $summary['services_share'] }} do valor liquido atual.</p>
                    </div>

                    <div class="space-y-3 rounded-2xl border border-white/10 bg-black/10 p-4 backdrop-blur-sm">
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <span class="text-zinc-300">Produtos</span>
                            <span class="font-medium text-zinc-50">{{ $summary['products_total'] }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-white/10">
                            <div class="h-full rounded-full bg-gradient-to-r from-fuchsia-400 via-violet-400 to-purple-400" style="width: {{ $summary['products_share_width'] }}"></div>
                        </div>
                        <p class="text-sm text-zinc-400">{{ $summary['products_share'] }} do valor liquido atual.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-3 2xl:grid-cols-1">
            @foreach ($statusCards as $card)
                @php
                    $theme = match ($card['color']) {
                        'amber' => 'from-amber-500/18 via-white to-white border-amber-200/70 text-amber-950 dark:from-amber-500/10 dark:via-zinc-900 dark:to-zinc-900 dark:border-amber-500/20 dark:text-amber-50',
                        'emerald' => 'from-emerald-500/18 via-white to-white border-emerald-200/70 text-emerald-950 dark:from-emerald-500/10 dark:via-zinc-900 dark:to-zinc-900 dark:border-emerald-500/20 dark:text-emerald-50',
                        default => 'from-rose-500/18 via-white to-white border-rose-200/70 text-rose-950 dark:from-rose-500/10 dark:via-zinc-900 dark:to-zinc-900 dark:border-rose-500/20 dark:text-rose-50',
                    };

                    $pill = match ($card['color']) {
                        'amber' => 'bg-amber-500/12 text-amber-700 ring-amber-500/20 dark:bg-amber-400/10 dark:text-amber-300',
                        'emerald' => 'bg-emerald-500/12 text-emerald-700 ring-emerald-500/20 dark:bg-emerald-400/10 dark:text-emerald-300',
                        default => 'bg-rose-500/12 text-rose-700 ring-rose-500/20 dark:bg-rose-400/10 dark:text-rose-300',
                    };
                @endphp

                <article class="rounded-3xl border bg-gradient-to-br p-5 shadow-[0_20px_60px_-40px_rgba(15,23,42,0.65)] {{ $theme }}">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm font-medium opacity-80">{{ $card['label'] }}</p>
                            <p class="mt-3 text-3xl font-semibold tracking-tight">{{ number_format($card['value'], 0, ',', '.') }}</p>
                        </div>

                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset {{ $pill }}">
                            {{ $card['description'] }}
                        </span>
                    </div>
                </article>
            @endforeach
        </section>
    </div>
</x-filament-widgets::widget>
