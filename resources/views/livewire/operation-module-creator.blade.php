<div>
    @teleport('body')
        <x-operation.theme>
            <x-mary-modal wire:model="showModal" :title="\App\Filament\Operation\OperationResourceSupport::definition($resourceModule)['create']" box-class="max-w-3xl" class="backdrop-blur-sm">
                @if ($showModal)
                    <x-mary-form wire:submit="create" no-separator>
                        @include('filament.operation.resources.form-fields')
                        <x-slot:actions>
                            <x-mary-button label="Voltar" @click="$wire.showModal = false" />
                            <x-mary-button label="Criar" class="btn-primary" type="submit" spinner="create" />
                        </x-slot:actions>
                    </x-mary-form>
                @endif
            </x-mary-modal>
        </x-operation.theme>
    @endteleport
</div>
