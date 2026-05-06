<x-filament-panels::page>
    @php($request = $this->requestRecord)

    <div class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                <div class="text-sm text-gray-500 dark:text-gray-400">Empresa</div>
                <div class="mt-1 font-medium">{{ $request?->company?->name }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                <div class="text-sm text-gray-500 dark:text-gray-400">Série</div>
                <div class="mt-1 font-medium">{{ $request?->serie }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                <div class="text-sm text-gray-500 dark:text-gray-400">Número</div>
                <div class="mt-1 font-medium">{{ $request?->number_start }}@if($request?->number_end !== $request?->number_start) - {{ $request?->number_end }}@endif</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                <div class="text-sm text-gray-500 dark:text-gray-400">Status</div>
                <div class="mt-1 font-medium">{{ strtoupper((string) $request?->status) }}</div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
            <div class="text-sm text-gray-500 dark:text-gray-400">Justificativa</div>
            <div class="mt-2 whitespace-pre-wrap">{{ $request?->justification }}</div>
        </div>

        @if(filled($request?->error_message))
            <div class="rounded-xl border border-danger-200 bg-danger-50 p-4 text-danger-700 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-200">
                <div class="font-medium">Último erro</div>
                <div class="mt-1 whitespace-pre-wrap">{{ $request?->error_message }}</div>
            </div>
        @endif

        @if($request?->fiscalDocument)
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/5">
                <div class="text-sm text-gray-500 dark:text-gray-400">Documento relacionado</div>
                <div class="mt-1 font-medium">NF-e #{{ $request->fiscalDocument->document_number ?? $request->fiscalDocument->id }}</div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
