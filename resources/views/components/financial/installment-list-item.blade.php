@props(['installment', 'type', 'url' => null])

@php
    $account = $type === 'receivable' ? $installment->accountReceivable : $installment->accountPayable;
    $overdue = $installment->due_date->lt(today());
    $status = $installment->status;
    $statusLabel = $status->value === 'overdue' ? 'Pendente' : $status->description();
    $statusColor = $status->value === 'overdue' ? 'warning' : $status->color();
    $deadlineLabel = $overdue
        ? 'Vencido há ' . (int) $installment->due_date->diffInDays(today()) . ' dia(s)'
        : ($installment->due_date->isToday() ? 'Vence hoje' : 'A vencer');
@endphp

<div role="listitem" wire:key="{{ $type }}-{{ $installment->id }}">
    <x-mary-list-item :item="$installment" :id="$type . '-' . $installment->id" class="financial-installment" no-separator>
        <x-slot:avatar>
            <span @class(['financial-installment__icon', 'financial-installment__icon--payable' => $type === 'payable', 'financial-installment__icon--receivable' => $type === 'receivable'])>
                <x-mary-icon :name="$type === 'payable' ? 'o-arrow-up-right' : 'o-arrow-down-left'" />
            </span>
        </x-slot:avatar>

        <x-slot:value class="financial-installment__title">
            {{ $account->counterparty_label }}
        </x-slot:value>

        <x-slot:sub-value class="financial-installment__details">
            <span class="financial-installment__document">{{ $account->document_number ?: 'Sem documento' }} · Parcela {{ $installment->sequence_number }}</span>
            @if($description = $installment->description ?: $account->description)
                <span class="financial-installment__description">{{ $description }}</span>
            @endif
            <span class="financial-installment__date">
                <x-mary-icon name="o-calendar-days" />
                Vencimento {{ $installment->due_date->format('d/m/Y') }}
            </span>
            <span class="financial-installment__badges">
                <x-filament::badge :color="$statusColor">{{ $statusLabel }}</x-filament::badge>
                <x-filament::badge :color="$overdue ? 'danger' : ($installment->due_date->isToday() ? 'warning' : 'gray')">{{ $deadlineLabel }}</x-filament::badge>
            </span>
        </x-slot:sub-value>

        <x-slot:actions class="financial-installment__actions">
            <span class="financial-installment__amount">R$ {{ number_format($installment->balance_amount, 2, ',', '.') }}</span>
            <span class="financial-installment__balance-label">Saldo em aberto</span>
            @if($url)
                <x-filament::link :href="$url" icon="heroicon-o-arrow-top-right-on-square" icon-position="after">Abrir conta</x-filament::link>
            @endif
        </x-slot:actions>
    </x-mary-list-item>
</div>
