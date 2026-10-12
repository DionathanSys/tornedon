<x-operation.panel title="Defeitos relatados" subtitle="Relato do proprietário ou condutor para orientar a equipe de mecânicos">
    <p class="mb-4 font-semibold">{{ $order['equipment_name'] }} · {{ $order['equipment_identifier'] }}</p>
    @if($order['can_edit'])
        <x-mary-form wire:submit="save" no-separator>
            @foreach($formData['defects'] ?? [] as $index => $defect)
                <div class="space-y-4 rounded-box bg-base-200 p-4" wire:key="defect-{{ $defect['id'] ?? 'new' }}-{{ $index }}">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-semibold">Defeito {{ $index + 1 }}</h3>
                        <x-mary-button label="Remover" icon="o-trash" class="btn-ghost btn-sm" :wire:click="'removeDefect('.$index.')'" wire:confirm="Remover este defeito da ficha? A alteração será efetivada ao salvar." />
                    </div>
                    <x-mary-textarea label="Defeito citado" :wire:model="'formData.defects.'.$index.'.report'" rows="3" maxlength="10000" />
                </div>
            @endforeach
            @error('formData.defects')<p class="text-error text-sm">{{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-3">
                <x-mary-button label="Adicionar defeito" icon="o-plus" wire:click="addDefect" class="btn-outline" />
                <x-mary-button label="Salvar ficha" type="submit" class="btn-primary" spinner="save" />
            </div>
            <p class="text-sm text-base-content/60">A impressão utiliza os dados salvos. Salve a ficha antes de imprimir.</p>
        </x-mary-form>
    @else
        <div class="my-4 space-y-4">
            @forelse($order['defects'] as $defect)
                <div class="rounded-box bg-base-200 p-4 space-y-2">
                    <h3 class="font-semibold">Defeito {{ $loop->iteration }}</h3>
                    <div><p class="text-sm text-base-content/60">Defeito citado</p><p class="whitespace-pre-wrap">{{ $defect['report'] }}</p></div>
                </div>
            @empty
                <p>Nenhum defeito registrado na lista.</p>
            @endforelse
        </div>
    @endif
</x-operation.panel>
