<div class="bpo-workbench bpo-record-attachments" x-data="{ uploading: false, progress: 0 }" x-on:livewire-upload-start="uploading = true; progress = 0" x-on:livewire-upload-finish="uploading = false" x-on:livewire-upload-error="uploading = false" x-on:livewire-upload-cancel="uploading = false" x-on:livewire-upload-progress="progress = $event.detail.progress">
    <header class="bpo-workbench-header"><div><h2>{{ $heading }}</h2><p>Documentos e comprovantes deste registro.</p></div><span class="bpo-badge">{{ $attachments->count() }}</span></header>
    <div class="bpo-attachment-toolbar">
        @if ($uploadsEnabled && $canWrite && $types->isNotEmpty())<x-filament::button color="gray" size="sm" icon="heroicon-o-paper-clip" wire:click="openUpload">Adicionar arquivos</x-filament::button>@else<span class="bpo-attachment-readonly"><x-filament::icon icon="heroicon-o-eye" />Consulta de documentos</span>@endif
        <small>Arquivos vinculados a esta conta</small>
    </div>

    @if ($showUpload && $uploadsEnabled && $canWrite)
        <form class="bpo-attachment-upload" wire:submit="uploadAttachments">
            <label class="bpo-attachment-field"><span>Tipo do documento</span><select wire:model.live="attachmentType" aria-label="Tipo do documento">@foreach ($types as $type)<option value="{{ $type->value }}">{{ $type->getLabel() }}</option>@endforeach</select></label>
            @error('attachmentType')<p class="bpo-attachment-error" role="alert">{{ $message }}</p>@enderror
            <label class="bpo-attachment-dropzone"><x-filament::icon icon="heroicon-o-cloud-arrow-up" /><strong>Selecione ou arraste os arquivos</strong><small>Até 10 arquivos · {{ $limitLabel }} por arquivo</small><input type="file" multiple wire:model="files" accept="{{ $acceptedMimes }}" aria-label="Arquivos para anexar" /></label>
            <p class="bpo-attachment-progress" x-show="uploading" x-cloak role="status">Preparando arquivos… <span x-text="progress + '%'"></span></p>
            @if ($errors->has('files') || $errors->has('files.*'))<p class="bpo-attachment-error" role="alert">{{ $errors->first('files') ?: $errors->first('files.*') }}</p>@endif
            @if ($pendingFiles->isNotEmpty())<ul class="bpo-attachment-pending">@foreach ($pendingFiles as $file)<li><span>{{ $file['name'] }}</span><small>{{ $file['size'] }}</small></li>@endforeach</ul>@endif
            <label class="bpo-attachment-field"><span>Descrição <small>opcional</small></span><textarea wire:model="description" rows="2" maxlength="2000" placeholder="Ex.: comprovante, contrato ou referência do documento"></textarea></label>
            @error('description')<p class="bpo-attachment-error" role="alert">{{ $message }}</p>@enderror
            <div class="bpo-attachment-form-actions"><x-filament::button size="sm" type="submit" x-bind:disabled="uploading" wire:loading.attr="disabled" wire:target="uploadAttachments">Enviar arquivos</x-filament::button><x-filament::button color="gray" size="sm" wire:click="closeUpload" x-bind:disabled="uploading">Cancelar</x-filament::button></div>
        </form>
    @endif

    <div class="bpo-attachment-list">
        @forelse ($attachments as $attachment)
            <article class="bpo-attachment-item" wire:key="attachment-{{ $attachment->id }}">
                <div class="bpo-attachment-file"><span class="bpo-attachment-file-icon"><x-filament::icon :icon="$attachment->isPreviewableImage() ? 'heroicon-o-photo' : 'heroicon-o-document-text'" /></span><div><strong>{{ $attachment->original_name }}</strong><small>{{ $attachment->type->getLabel() }} · {{ \Illuminate\Support\Number::fileSize($attachment->size_bytes) }}</small></div></div>
                @if ($editingId === $attachment->id)
                    <form class="bpo-attachment-edit" wire:submit="saveDescription"><label class="bpo-attachment-field"><span>Descrição do anexo</span><textarea wire:model="editingDescription" rows="2" maxlength="2000"></textarea></label>@error('editingDescription')<p class="bpo-attachment-error" role="alert">{{ $message }}</p>@enderror<div class="bpo-attachment-form-actions"><x-filament::button type="submit" size="sm">Salvar</x-filament::button><x-filament::button color="gray" size="sm" wire:click="cancelDescription">Cancelar</x-filament::button></div></form>
                @elseif ($attachment->description)
                    <p class="bpo-attachment-description">{{ $attachment->description }}</p>
                @endif
                <div class="bpo-attachment-meta"><span>{{ $attachment->created_at->format('d/m/Y H:i') }}</span>@if ($attachment->uploader)<span>{{ $attachment->uploader->name }}</span>@endif</div>
                <div class="bpo-attachment-item-actions">@if ($attachment->isPreviewable())<button type="button" wire:click="viewAttachment({{ $attachment->id }})"><x-filament::icon icon="heroicon-o-eye" />Visualizar</button>@endif<a href="{{ $attachment->url }}" target="_blank" rel="noopener"><x-filament::icon icon="heroicon-o-arrow-down-tray" />Baixar</a>@if ($uploadsEnabled && $canWrite)<button type="button" wire:click="editDescription({{ $attachment->id }})" aria-label="Editar descrição de {{ $attachment->original_name }}" title="Editar descrição"><x-filament::icon icon="heroicon-o-pencil-square" /></button>@endif @if ($deletionsEnabled && $canWrite)<button type="button" class="is-danger" wire:click="deleteAttachment({{ $attachment->id }})" wire:confirm="Remover o anexo '{{ $attachment->original_name }}' deste lançamento?" aria-label="Remover {{ $attachment->original_name }}" title="Remover anexo"><x-filament::icon icon="heroicon-o-trash" /></button>@endif</div>
            </article>
        @empty
            <div class="bpo-attachment-empty"><x-filament::icon icon="heroicon-o-document-plus" /><strong>Nenhum anexo neste lançamento</strong><p>{{ $uploadsEnabled && $canWrite ? 'Adicione notas, contratos e comprovantes para manter os documentos junto da conta.' : 'Os documentos vinculados a este registro aparecerão aqui.' }}</p></div>
        @endforelse
    </div>
    <footer class="bpo-workbench-footer"><x-filament::icon icon="heroicon-o-lock-closed" /><span>Documentos protegidos pelo acesso ao lançamento.</span></footer>

    <x-filament::modal :id="$this->previewModalId()" width="7xl">
        <x-slot name="heading">{{ $preview['name'] ?? 'Visualizar anexo' }}</x-slot>
        @if ($preview)
            @if ($preview['image'])<img class="bpo-attachment-preview-image" src="{{ $preview['url'] }}" alt="{{ $preview['name'] }}" />@else<iframe class="bpo-attachment-preview-frame" src="{{ $preview['url'] }}" title="{{ $preview['name'] }}"></iframe>@endif
            @if ($preview['description'])<p class="bpo-attachment-description">{{ $preview['description'] }}</p>@endif
            <a class="bpo-button is-secondary" href="{{ $preview['download'] }}" target="_blank" rel="noopener">Baixar arquivo</a>
        @endif
    </x-filament::modal>
</div>
