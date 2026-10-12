<div>
    <x-operation.panel :title="'Anexos ('.count($attachments).')'">
        <x-mary-button label="Incluir anexos" icon="o-paper-clip" class="btn-outline btn-sm" wire:click="$toggle('showUpload')" />
        @if ($showUpload)
            <x-mary-form wire:submit="uploadAttachments" no-separator class="rounded-box border border-base-300 bg-base-200 p-4">
                <x-mary-select label="Tipo de anexo" wire:model="attachmentType" :options="$types" />
                <x-mary-file label="Arquivo(s)" wire:model="files" multiple hint="Selecione os arquivos. Os limites de tamanho e formato seguem o tipo de anexo." />
                <x-slot:actions>
                    <x-mary-button label="Cancelar" @click="$wire.showUpload = false" />
                    <x-mary-button label="Enviar anexos" icon="o-arrow-up-tray" class="btn-primary" type="submit" spinner="uploadAttachments" wire:loading.attr="disabled" wire:target="files,uploadAttachments" />
                </x-slot:actions>
            </x-mary-form>
        @endif
        <div class="space-y-3">
            @forelse ($attachments as $attachment)
                <div class="flex flex-col justify-between gap-3 rounded-box border border-base-300 p-3 sm:flex-row sm:items-center" wire:key="operation-attachment-{{ $attachment['id'] }}">
                    <div class="min-w-0">
                        <p class="break-words text-sm font-medium">{{ $attachment['name'] }}</p>
                        <p class="mt-1 text-xs text-base-content/60">{{ $attachment['type'] }} · {{ $attachment['size'] }} · {{ $attachment['date'] }}</p>
                        @if ($attachment['uploader'])
                            <p class="text-xs text-base-content/60">Enviado por {{ $attachment['uploader'] }}</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 gap-2">
                        @if ($attachment['previewable'])
                            <x-mary-button :id="'view-attachment-'.$attachment['id']" icon="o-eye" class="btn-ghost btn-sm btn-circle" :wire:click="'viewAttachment('.$attachment['id'].')'" aria-label="Visualizar anexo" tooltip="Visualizar" />
                        @endif
                        <x-mary-button :id="'download-attachment-'.$attachment['id']" icon="o-arrow-down-tray" class="btn-ghost btn-sm btn-circle" :link="$attachment['url']" external aria-label="Baixar anexo" tooltip="Baixar" />
                        <x-mary-button :id="'delete-attachment-'.$attachment['id']" icon="o-trash" class="btn-ghost btn-sm btn-circle text-error" :wire:click="'requestAttachmentDeletion('.$attachment['id'].')'" aria-label="Remover anexo" tooltip="Remover" />
                    </div>
                </div>
            @empty
                <p class="text-sm text-base-content/60">Nenhum anexo incluído nesta OS.</p>
            @endforelse
        </div>
    </x-operation.panel>

    <x-mary-modal wire:model="showDeleteModal" title="Remover anexo?" :subtitle="$deletingName" class="backdrop-blur-sm">
        <x-slot:actions>
            <x-mary-button label="Voltar" @click="$wire.showDeleteModal = false" />
            <x-mary-button label="Remover" class="btn-error" wire:click="deleteAttachment" spinner="deleteAttachment" />
        </x-slot:actions>
    </x-mary-modal>
    <x-mary-modal wire:model="showPreviewModal" :title="$preview['name'] ?? 'Visualizar anexo'" box-class="max-w-4xl" class="backdrop-blur-sm">
        @if ($showPreviewModal && $preview)
            @if ($preview['image'])
                <img src="{{ $preview['url'] }}" alt="{{ $preview['name'] }}" class="mx-auto max-h-[65dvh] max-w-full object-contain" />
            @else
                <iframe src="{{ $preview['url'] }}" title="{{ $preview['name'] }}" class="h-[65dvh] w-full rounded-box border border-base-300"></iframe>
            @endif
        @endif
        <x-slot:actions>
            <x-mary-button label="Fechar" @click="$wire.showPreviewModal = false" />
        </x-slot:actions>
    </x-mary-modal>
</div>
