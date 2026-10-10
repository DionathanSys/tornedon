@props(['value', 'color' => 'gray'])
@php
    $class = match ($color) {
        'success', 'closed' => 'badge-success',
        'warning', 'invoiced' => 'badge-warning',
        'danger', 'cancelled' => 'badge-error',
        'info', 'open' => 'badge-info',
        default => 'badge-neutral',
    };
@endphp
<x-mary-badge :value="$value" :class="'badge-soft badge-sm '.$class" />
