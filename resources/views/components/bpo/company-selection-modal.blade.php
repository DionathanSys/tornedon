@props(['companies'])

@once
    @push('styles')
        <style>
            .bpo-company-dialog .fi-modal-window { background: var(--bpo-surface); color: var(--bpo-ink); border: 1px solid var(--bpo-border); border-radius: 18px; }
            .bpo-company-dialog .fi-modal-header { padding: 26px 26px 20px; }
            .bpo-company-dialog .fi-modal-content { padding: 0 26px; }
            .bpo-company-dialog .fi-modal-footer { padding: 20px 26px 24px; }
            .bpo-company-dialog-body { display: flex; flex-direction: column; gap: 18px; padding-top: 6px; }
            .bpo-company-dialog-search { display: flex; align-items: center; gap: 12px; padding: 0 14px; min-height: 46px; background: var(--bpo-raised); border: 1px solid var(--bpo-border); border-radius: 10px; }
            .bpo-company-dialog-search svg { color: var(--bpo-muted); width: 18px; height: 18px; flex: none; }
            .bpo-company-dialog-search:focus-within { border-color: var(--bpo-teal); box-shadow: 0 0 0 3px var(--bpo-icon-bg); }
            .bpo-company-dialog-search input { width: 100%; min-width: 0; padding: 12px 0; background: transparent; color: var(--bpo-ink); border: 0; box-shadow: none; outline: none; font-size: 13px; }
            .bpo-company-dialog-search input:focus { outline: none; box-shadow: none; }
            .bpo-company-dialog-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
            .bpo-company-dialog-count { display: inline-flex; gap: 5px; align-items: center; padding: 6px 10px; border-radius: 7px; background: var(--bpo-badge-bg); color: var(--bpo-teal); font-size: 12px; font-weight: 600; }
            .bpo-company-dialog-tools { display: flex; align-items: center; gap: 14px; }
            .bpo-company-dialog-tools button { padding: 5px 0; font-size: 12px; color: var(--bpo-teal); font-weight: 600; }
            .bpo-company-dialog-tools button:last-child { color: var(--bpo-muted); }
            .bpo-company-dialog-list { display: flex; flex-direction: column; gap: 8px; max-height: 330px; min-height: 130px; overflow-y: auto; padding: 3px; margin: -3px; }
            .bpo-company-dialog-option { display: flex; align-items: center; gap: 14px; min-height: 72px; padding: 14px 16px; border: 1px solid var(--bpo-border); border-radius: 11px; cursor: pointer; background: var(--bpo-surface); transition: background .15s, border-color .15s; }
            .bpo-company-dialog-option:hover { background: var(--bpo-hover); }
            .bpo-company-dialog-option:has(input:checked) { border-color: var(--bpo-teal); background: var(--bpo-badge-bg); }
            .bpo-company-dialog-option input { width: 17px; height: 17px; flex: none; accent-color: var(--bpo-button-bg); border-radius: 4px; }
            .bpo-company-dialog-avatar { display: grid; place-items: center; width: 38px; height: 38px; flex: none; background: var(--bpo-avatar-bg); color: var(--bpo-teal); border-radius: 10px; font-size: 14px; font-weight: 650; }
            .bpo-company-dialog-name { min-width: 0; display: grid; gap: 5px; }
            .bpo-company-dialog-name strong { font-size: 13px; font-weight: 600; overflow-wrap: anywhere; }
            .bpo-company-dialog-name small { color: var(--bpo-muted); font-size: 11px; }
            .bpo-company-dialog-empty { text-align: center; padding: 32px 18px; color: var(--bpo-muted); background: var(--bpo-raised); border-radius: 10px; font-size: 12px; }
            .bpo-company-dialog-hint { display: flex; align-items: flex-start; gap: 9px; color: var(--bpo-muted); font-size: 11px; line-height: 1.7; padding-bottom: 4px; }
            .bpo-company-dialog-hint svg { width: 15px; height: 15px; flex: none; margin-top: 2px; }
            .bpo-company-dialog-footer { display: flex; align-items: center; justify-content: flex-end; gap: 12px; padding-top: 18px; border-top: 1px solid var(--bpo-divider); width: 100%; }
            .bpo-company-dialog-footer .bpo-button { min-height: 40px; padding-inline: 18px; }
        </style>
    @endpush
@endonce

<x-filament::modal id="cash-flow-companies" width="2xl" class="bpo-company-dialog">
    <x-slot name="heading">Empresas da análise</x-slot>
    <x-slot name="description">Escolha uma empresa ou combine os resultados da sua carteira.</x-slot>
    <div class="bpo-company-dialog-body" x-data="{
        search: '',
        names: @js($companies->map(fn ($company) => $company->name.' '.$company->document_number)->values()->all()),
        normalize(value) { return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[.\/\s-]/g, '').toLowerCase() },
        get hasResults() { return this.names.some(name => this.normalize(name).includes(this.normalize(this.search))) }
    }" x-on:open-modal.window="if ($event.detail.id === 'cash-flow-companies') search = ''">
        <label class="bpo-company-dialog-search"><x-filament::icon icon="heroicon-o-magnifying-glass" /><span class="bpo-sr-only">Buscar empresa por nome ou CNPJ</span><input type="search" x-model="search" placeholder="Buscar empresa por nome ou CNPJ" /></label>
        <div class="bpo-company-dialog-toolbar">
            <span class="bpo-company-dialog-count" aria-live="polite"><span x-text="$wire.draftCompanyIds.length"></span> de {{ $companies->count() }} selecionadas</span>
            <div class="bpo-company-dialog-tools">
                <button type="button" wire:click="selectAllCompanies">Selecionar todas</button>
                <button type="button" x-on:click="$wire.$set('draftCompanyIds', [], false)">Limpar seleção</button>
            </div>
        </div>
        <div class="bpo-company-dialog-list">
            @foreach ($companies as $company)
                <label class="bpo-company-dialog-option" wire:key="flow-company-{{ $company->id }}" x-show="normalize(@js($company->name.' '.$company->document_number)).includes(normalize(search))">
                    <input type="checkbox" wire:model="draftCompanyIds" value="{{ $company->id }}" />
                    <span class="bpo-company-dialog-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($company->name, 0, 1)) }}</span>
                    <span class="bpo-company-dialog-name"><strong>{{ $company->name }}</strong><small>{{ $company->document_number ?: 'CNPJ não informado' }}</small></span>
                </label>
            @endforeach
            <div class="bpo-company-dialog-empty" x-show="! hasResults" x-cloak>Nenhuma empresa encontrada. Tente outro nome ou CNPJ.</div>
        </div>
        <div class="bpo-company-dialog-hint"><x-filament::icon icon="heroicon-o-information-circle" /><span>A seleção só altera o fluxo de caixa depois de clicar em <strong>Aplicar seleção</strong>.</span></div>
    </div>
    <x-slot name="footer">
        <div class="bpo-company-dialog-footer">
            <button type="button" class="bpo-button is-secondary" x-on:click="$dispatch('close-modal', { id: 'cash-flow-companies' })">Cancelar</button>
            <button type="button" class="bpo-button" wire:click="applyCompanies" wire:loading.attr="disabled" wire:target="applyCompanies"><x-filament::icon icon="heroicon-o-check" />Aplicar seleção</button>
        </div>
    </x-slot>
</x-filament::modal>
