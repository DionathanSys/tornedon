@php
    $pollingInterval = $this->getAutoRefreshInterval();
@endphp

<div @if ($pollingInterval) wire:poll.{{ $pollingInterval }}="refreshInvoiceState" @endif>
    <x-filament-panels::page>
        {{ $this->content }}
    </x-filament-panels::page>
</div>
