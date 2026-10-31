<div class="space-y-4">
    <p class="text-sm text-gray-500">As 20 exportações mais recentes solicitadas por você nesta empresa. Reabra esta janela para atualizar o processamento.</p>
    @forelse ($exports as $export)
        <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <p class="font-medium">{{ $export->starts_at?->format('d/m/Y') }} a {{ $export->ends_at?->format('d/m/Y') }}</p>
            <p class="text-sm">
                {{ match ($export->status) {
                    'pending' => 'Na fila',
                    'processing' => 'Em processamento',
                    'completed' => 'Concluída',
                    'completed_with_errors' => 'Concluída com erros',
                    'failed' => 'Falhou',
                    'expired' => 'Expirada',
                    default => 'Indisponível',
                } }}
                · {{ $export->successful_documents ?? 0 }}/{{ $export->total_documents }} XML(s)
            </p>
            @if ($export->error_message)
                <p class="text-sm text-danger-600">{{ $export->error_message }}</p>
            @endif
            @if ($export->isDownloadAvailable())
                <x-filament::link :href="\Illuminate\Support\Facades\URL::temporarySignedRoute('fiscal-document-xml-exports.download', $export->download_expires_at, ['export' => $export->id, 'token' => $export->download_token])" target="_blank" rel="noopener noreferrer" icon="heroicon-o-arrow-down-tray">
                    Baixar ZIP · válido até {{ $export->download_expires_at->format('d/m/Y H:i') }}
                </x-filament::link>
            @elseif ($export->download_expires_at?->isPast())
                <p class="text-sm text-gray-500">Link expirado. Solicite uma nova exportação.</p>
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500">Você ainda não solicitou exportações XML nesta empresa.</p>
    @endforelse
</div>
