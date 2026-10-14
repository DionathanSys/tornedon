@php
    $module = $this->moduleKey();
    $cash = $module === 'cash_movements';
    $state = $cash ? $record->direction : $record->status;
@endphp
<x-operation.page :title="$this->getTitle()" :subtitle="$record->counterparty_label">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
        <x-operation.stat title="Valor" :value="\App\Filament\Operation\OperationResourceSupport::money($cash ? $record->amount : $record->due_amount)" />
        <x-operation.stat title="Status" :value="$state->description()" />
        @if (! $cash)
            <x-operation.stat :title="$module === 'account_receivables' ? 'Recebido' : 'Pago'" :value="\App\Filament\Operation\OperationResourceSupport::money($record->paid_amount)" />
        @endif
    </div>
    <x-operation.panel title="Dados do registro">
        <x-mary-form wire:submit="save" no-separator>
            @include('filament.operation.resources.form-fields')
        </x-mary-form>
    </x-operation.panel>
    @if (! $cash)
        <x-operation.panel title="Parcelas">
            <div class="space-y-3">
                @forelse ($record->installments()->orderBy('sequence_number')->get() as $installment)
                    <div class="rounded-box bg-base-200 p-4" wire:key="installment-{{ $installment->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold">Parcela {{ $installment->sequence_number }} · {{ $installment->due_date?->format('d/m/Y') }}</p>
                                <p class="mt-1 text-sm text-base-content/60">Valor: {{ \App\Filament\Operation\OperationResourceSupport::money($installment->due_amount) }} · Saldo: {{ \App\Filament\Operation\OperationResourceSupport::money($installment->balance_amount) }}</p>
                            </div>
                            <x-operation.status :value="$installment->status->description()" :color="$installment->status->color()" />
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">Nenhuma parcela encontrada.</p>
                @endforelse
            </div>
        </x-operation.panel>
        @include('filament.operation.resources.payment-modal')
    @endif
    {{ $this->getFooter() }}
</x-operation.page>
