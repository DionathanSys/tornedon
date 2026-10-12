<x-operation.panel title="Ficha operacional" subtitle="Defeitos relatados e acompanhamento da equipe de mecânicos">
    <p class="mb-4 font-semibold">{{ $order['equipment_name'] }} · {{ $order['equipment_identifier'] }}</p>
    @if($order['can_edit'])
        <x-mary-form wire:submit="save" no-separator>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-mary-input label="Nome de quem relatou" wire:model="formData.reported_by_name" maxlength="255" />
                <x-mary-select label="Quem informou os defeitos?" wire:model="formData.reported_by_role" :options="[['id' => 'owner', 'name' => 'Proprietário'], ['id' => 'driver', 'name' => 'Condutor']]" placeholder="Selecione" />
                <x-mary-input label="Quilometragem na entrada" wire:model="formData.entry_mileage" type="number" min="0" max="4294967295" suffix="km" />
            </div>
            @foreach($formData['defects'] ?? [] as $index => $defect)
                <div class="space-y-4 rounded-box bg-base-200 p-4" wire:key="defect-{{ $defect['id'] ?? 'new' }}-{{ $index }}">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="font-semibold">Defeito {{ $index + 1 }}</h3>
                        <x-mary-button label="Remover" icon="o-trash" class="btn-ghost btn-sm" :wire:click="'removeDefect('.$index.')'" wire:confirm="Remover este defeito da ficha? A alteração será efetivada ao salvar." />
                    </div>
                    <x-mary-textarea label="Relato do proprietário / condutor" :wire:model="'formData.defects.'.$index.'.report'" rows="2" maxlength="10000" />
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-mary-textarea label="Diagnóstico técnico" :wire:model="'formData.defects.'.$index.'.diagnosis'" rows="3" maxlength="10000" />
                        <x-mary-textarea label="Providência / solução aplicada" :wire:model="'formData.defects.'.$index.'.solution'" rows="3" maxlength="10000" />
                    </div>
                    <x-mary-select label="Situação" :wire:model="'formData.defects.'.$index.'.status'" :options="collect(\App\Models\ServiceOrderDefect::STATUSES)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values()->all()" />
                </div>
            @endforeach
            @error('formData.defects')<p class="text-error text-sm">{{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-3">
                <x-mary-button label="Adicionar defeito" icon="o-plus" wire:click="addDefect" class="btn-outline" />
                <x-mary-button label="Salvar ficha" type="submit" class="btn-primary" spinner="save" />
                <x-mary-button label="Imprimir ficha operacional" icon="o-printer" wire:click="printOperationalSheet" spinner="printOperationalSheet" />
            </div>
            <p class="text-sm text-base-content/60">A impressão utiliza os dados salvos. Salve a ficha antes de imprimir.</p>
        </x-mary-form>
    @else
        <p>{{ $order['reported_by_name'] ?: 'Nome não informado' }} · {{ ['owner' => 'Proprietário', 'driver' => 'Condutor'][$order['reported_by_role']] ?? 'Vínculo não informado' }} · {{ $order['entry_mileage'] !== null ? number_format($order['entry_mileage'], 0, ',', '.').' km' : 'Quilometragem não informada' }}</p>
        <div class="my-4 space-y-4">
            @forelse($order['defects'] as $defect)
                <div class="rounded-box bg-base-200 p-4 space-y-2">
                    <h3 class="font-semibold">Defeito {{ $loop->iteration }} · {{ \App\Models\ServiceOrderDefect::STATUSES[$defect['status']] ?? $defect['status'] }}</h3>
                    @foreach(['report' => 'Relato do proprietário / condutor', 'diagnosis' => 'Diagnóstico técnico', 'solution' => 'Providência / solução aplicada'] as $field => $label)
                        <div><p class="text-sm text-base-content/60">{{ $label }}</p><p class="whitespace-pre-wrap">{{ $defect[$field] ?: '—' }}</p></div>
                    @endforeach
                </div>
            @empty
                <p>Nenhum defeito registrado na lista.</p>
            @endforelse
        </div>
        <x-mary-button label="Imprimir ficha operacional" icon="o-printer" wire:click="printOperationalSheet" spinner="printOperationalSheet" />
    @endif
</x-operation.panel>
