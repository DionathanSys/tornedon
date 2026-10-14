<x-operation.page :title="$this->getTitle()">
    <x-operation.panel title="Dados do registro">
        <x-mary-form wire:submit="create" no-separator>
            @include('filament.operation.resources.form-fields')
        </x-mary-form>
    </x-operation.panel>
    <x-operation.bottom-bar :columns="2" label="Ações do registro">
        <x-mary-button label="Lista" icon="o-arrow-left" :link="$this->getResource()::getUrl()" class="btn-ghost operation-bar-button" />
        <x-mary-button label="Criar" icon="o-check" wire:click="create" spinner="create" class="btn-primary operation-bar-button" />
    </x-operation.bottom-bar>
</x-operation.page>
