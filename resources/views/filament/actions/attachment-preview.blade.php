<div class="space-y-4">
    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                {{ $record->original_name }}
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ $record->mime_type ?: 'Tipo desconhecido' }}
            </p>
        </div>

        <a
            href="{{ $record->url }}"
            target="_blank"
            rel="noopener noreferrer"
            class="shrink-0 text-sm font-medium text-primary-600 hover:text-primary-500"
        >
            Baixar
        </a>
    </div>

    @if ($record->isPreviewableImage())
        <div class="flex max-h-[70vh] min-h-32 items-center justify-center overflow-auto rounded-lg bg-gray-50 p-2 dark:bg-gray-950">
            <img
                src="{{ $record->preview_url }}"
                alt="{{ $record->original_name }}"
                class="max-h-[65vh] max-w-full object-contain"
            >
        </div>
    @elseif ($record->isPreviewablePdf())
        <iframe
            src="{{ $record->preview_url }}"
            title="{{ $record->original_name }}"
            class="h-[70vh] min-h-96 w-full rounded-lg border border-gray-200 dark:border-gray-700"
        ></iframe>
    @elseif ($record->isPreviewableText())
        <iframe
            src="{{ $record->preview_url }}"
            title="{{ $record->original_name }}"
            sandbox
            class="h-[70vh] min-h-96 w-full rounded-lg border border-gray-200 bg-white dark:border-gray-700"
        ></iframe>
    @else
        <div class="rounded-lg border border-gray-200 p-6 text-center text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
            Este tipo de arquivo não pode ser visualizado no navegador.
            Use o botão "Baixar" para abrir o anexo.
        </div>
    @endif
</div>
