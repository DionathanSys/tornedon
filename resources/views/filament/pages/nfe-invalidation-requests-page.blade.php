<x-filament-panels::page>
    @php($request = $this->requestRecord)

    @if($request)
        <div class="space-y-6">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Empresa</div>
                    <div class="mt-1 font-medium">{{ $request->company?->name }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Série</div>
                    <div class="mt-1 font-medium">{{ $request->serie }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Número</div>
                    <div class="mt-1 font-medium">{{ $request->number_start }}@if($request->number_end !== $request->number_start) - {{ $request->number_end }}@endif</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Status</div>
                    <div class="mt-1 font-medium">{{ strtoupper((string) $request->status) }}</div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                <div class="text-sm text-gray-500 dark:text-gray-400">Justificativa</div>
                <div class="mt-2 whitespace-pre-wrap">{{ $request->justification }}</div>
            </div>

            @if(filled($request->error_message))
                <div class="rounded-xl border border-danger-200 bg-danger-50 p-4 text-danger-700 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-200">
                    <div class="font-medium">Último erro</div>
                    <div class="mt-1 whitespace-pre-wrap">{{ $request->error_message }}</div>
                </div>
            @endif

            @if($request->fiscalDocument)
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Documento relacionado</div>
                    <div class="mt-1 font-medium">NF-e #{{ $request->fiscalDocument->document_number ?? $request->fiscalDocument->id }}</div>
                </div>
            @endif
        </div>
    @else
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/5 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Empresa</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Série</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Número</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Solicitado por</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Processado em</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Ação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse($this->requests as $item)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">{{ $item['id'] }}</td>
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">{{ $item['company'] }}</td>
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">{{ $item['serie'] }}</td>
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">{{ $item['number_start'] }}@if($item['number_end'] !== $item['number_start']) - {{ $item['number_end'] }}@endif</td>
                                <td class="px-4 py-3 text-sm">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $item['status'] === 'pending' ? 'bg-warning-100 text-warning-700 dark:bg-warning-500/15 dark:text-warning-300' : ($item['status'] === 'completed' ? 'bg-success-100 text-success-700 dark:bg-success-500/15 dark:text-success-300' : 'bg-danger-100 text-danger-700 dark:bg-danger-500/15 dark:text-danger-300') }}">
                                        {{ strtoupper($item['status']) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">{{ $item['requested_by'] }}</td>
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">{{ $item['processed_at'] ?? '-' }}</td>
                                <td class="px-4 py-3 text-right text-sm">
                                    <a href="{{ $item['url'] }}" class="font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300">
                                        Abrir
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Nenhuma solicitação de inutilização encontrada.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
