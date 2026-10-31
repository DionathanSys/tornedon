@props(['month', 'active' => false])

@once
    @push('styles')
        <style>
            .bpo-month-control { display: inline-grid; grid-template-columns: 30px minmax(0, 1fr) 30px; align-items: center; gap: 3px; width: 266px; height: 40px; padding: 3px; border: 1px solid var(--bpo-border); border-radius: 9px; background: var(--bpo-raised); white-space: nowrap; }
            .bpo-month-control.is-active { border-color: var(--bpo-teal); background: var(--bpo-badge-bg); }
            .bpo-month-control > button,.bpo-month-control .bpo-month-trigger { display: inline-flex; align-items: center; justify-content: center; height: 32px; min-width: 0; padding: 0; border-radius: 6px; color: var(--bpo-muted); }
            .bpo-month-control > button:hover,.bpo-month-control .bpo-month-trigger:hover { background: var(--bpo-surface); color: var(--bpo-teal); }
            .bpo-month-control > .fi-dropdown,.bpo-month-control .fi-dropdown-trigger { width: 100%; min-width: 0; }
            .bpo-month-control .bpo-month-trigger { width: 100%; gap: 7px; color: var(--bpo-ink); font-size: 12px; font-weight: 600; }
            .bpo-month-value { display: inline-flex; align-items: center; gap: 7px; }
            .bpo-month-value small { color: var(--bpo-muted); font-size: 11px; font-weight: 400; }
            .bpo-month-control.is-active .bpo-month-trigger { color: var(--bpo-teal); }
            .bpo-month-control .bpo-month-trigger > span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .bpo-shell .bpo-month-control svg { width: 15px; height: 15px; flex: none; }
            .bpo-month-calendar { padding: 18px; background: var(--bpo-surface); color: var(--bpo-ink); border: 1px solid var(--bpo-border); border-radius: 12px; }
            .bpo-month-calendar-heading { margin-bottom: 14px; color: var(--bpo-muted); font-size: 10px; font-weight: 600; letter-spacing: 1px; }
            .bpo-month-year-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid var(--bpo-divider); }
            .bpo-month-year-nav strong { font-size: 18px; font-weight: 650; letter-spacing: -.5px; }
            .bpo-month-year-nav button { display: grid; place-items: center; width: 30px; height: 30px; border: 1px solid var(--bpo-border); border-radius: 7px; color: var(--bpo-muted); }
            .bpo-month-year-nav button:hover { background: var(--bpo-hover); }
            .bpo-month-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
            .bpo-month-grid button { padding: 12px 8px; border: 1px solid transparent; background: var(--bpo-raised); border-radius: 8px; font-size: 12px; }
            .bpo-month-grid button:hover { background: var(--bpo-hover); color: var(--bpo-teal); }
            .bpo-month-grid button.is-active { background: var(--bpo-badge-bg); border-color: var(--bpo-teal); color: var(--bpo-teal); font-weight: 600; }
            .bpo-month-calendar-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--bpo-divider); }
            .bpo-month-calendar-footer span { font-size: 10px; color: var(--bpo-muted); }
            .bpo-month-calendar-footer button { padding: 5px 8px; font-size: 11px; font-weight: 600; color: var(--bpo-teal); border-radius: 6px; background: var(--bpo-badge-bg); }
        </style>
    @endpush
@endonce

<div class="bpo-month-control {{ $active ? 'is-active' : '' }}" role="group" aria-label="Selecionar mês completo">
    <button type="button" wire:click="moveMonth(-1)" wire:loading.attr="disabled" aria-label="Mês anterior"><x-filament::icon icon="heroicon-o-chevron-left" /></button>
    <x-filament::dropdown placement="bottom-start" width="xs" teleport>
        <x-slot name="trigger">
            <button type="button" class="bpo-month-trigger" aria-label="Escolher mês completo" aria-haspopup="true"><x-filament::icon icon="heroicon-o-calendar-days" /><span class="bpo-month-value">{{ \Illuminate\Support\Str::ucfirst(\Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)->locale('pt_BR')->translatedFormat('F')) }}<small>{{ substr($month, 0, 4) }}</small></span><x-filament::icon icon="heroicon-o-chevron-down" /></button>
        </x-slot>
        <div class="bpo-month-calendar" x-data="{ year: Number($wire.month.slice(0, 4)) }" x-effect="year = Number($wire.month.slice(0, 4))">
            <div class="bpo-month-calendar-heading">ESCOLHA O MÊS DA ANÁLISE</div>
            <div class="bpo-month-year-nav">
                <button type="button" x-on:click="year = Math.max(1, year - 1)" aria-label="Ano anterior"><x-filament::icon icon="heroicon-o-chevron-left" /></button>
                <strong x-text="year"></strong>
                <button type="button" x-on:click="year = Math.min(9999, year + 1)" aria-label="Próximo ano"><x-filament::icon icon="heroicon-o-chevron-right" /></button>
            </div>
            <div class="bpo-month-grid">
                @foreach (['01' => 'Jan', '02' => 'Fev', '03' => 'Mar', '04' => 'Abr', '05' => 'Mai', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago', '09' => 'Set', '10' => 'Out', '11' => 'Nov', '12' => 'Dez'] as $number => $label)
                    <button type="button" x-on:click="$wire.selectMonth(String(year).padStart(4, '0') + '-{{ $number }}'); close()" x-bind:class="{ 'is-active': $wire.period === 'month' && $wire.month === String(year).padStart(4, '0') + '-{{ $number }}' }">{{ $label }}</button>
                @endforeach
            </div>
            <div class="bpo-month-calendar-footer"><span>Do primeiro ao último dia</span><button type="button" x-on:click="$wire.selectMonth('{{ today()->format('Y-m') }}'); close()">Mês atual</button></div>
        </div>
    </x-filament::dropdown>
    <button type="button" wire:click="moveMonth(1)" wire:loading.attr="disabled" aria-label="Próximo mês"><x-filament::icon icon="heroicon-o-chevron-right" /></button>
</div>
