<x-mary-collapse id="service-order-defects" class="bg-base-100 rounded-box border-base-300" separator>
    <x-slot:heading>Defeitos relatados</x-slot:heading>
    <x-slot:content>
    @if($order['can_edit'])
        <x-mary-form wire:submit="save" no-separator class="gap-2">
            @foreach($formData['defects'] ?? [] as $index => $defect)
                <div class="flex items-end gap-2" wire:key="defect-{{ $defect['id'] ?? 'new' }}-{{ $index }}">
                    <div class="min-w-0 flex-1">
                        <x-mary-textarea :label="'Defeito citado '.($index + 1)" :wire:model="'formData.defects.'.$index.'.report'" rows="2" maxlength="10000" class="textarea-sm" />
                    </div>
                    <x-mary-button icon="o-trash" class="btn-ghost btn-sm btn-square shrink-0" :aria-label="'Remover defeito '.($index + 1)" tooltip="Remover defeito" :wire:click="'removeDefect('.$index.')'" wire:confirm="Remover este defeito da ficha? A alteração será efetivada ao salvar." />
                </div>
            @endforeach
            @error('formData.defects')<p class="text-error text-sm">{{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-2">
                <x-mary-button label="Adicionar defeito" icon="o-plus" wire:click="addDefect" class="btn-outline btn-sm" />
                <x-mary-button label="Salvar ficha" type="submit" class="btn-primary btn-sm" spinner="save" />
            </div>
            <p class="text-xs text-base-content/60">Salve antes de imprimir pelo menu Mais.</p>
        </x-mary-form>
    @else
        <ol class="space-y-2">
            @forelse($order['defects'] as $defect)
                <li class="flex gap-2 text-sm"><span class="font-semibold">{{ $loop->iteration }}.</span><p class="whitespace-pre-wrap break-words min-w-0">{{ $defect['report'] }}</p></li>
            @empty
                <li class="text-sm text-base-content/60">Nenhum defeito registrado na lista.</li>
            @endforelse
        </ol>
    @endif
    </x-slot:content>
</x-mary-collapse>
