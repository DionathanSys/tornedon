@php
    $pollingInterval = $this->getAutoRefreshInterval();
@endphp

<div @if ($pollingInterval) wire:poll.{{ $pollingInterval }}="refreshFiscalDocumentState" @endif>
    <x-filament-panels::page>
        {{ $this->content }}
    </x-filament-panels::page>
</div>
