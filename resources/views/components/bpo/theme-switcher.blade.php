<label class="bpo-theme-switch">
    <x-filament::icon icon="heroicon-o-sun" class="bpo-theme-light-icon" />
    <x-filament::icon icon="heroicon-o-moon" class="bpo-theme-dark-icon" />
    <span class="bpo-sr-only">Tema do painel</span>
    <select data-bpo-theme-select aria-label="Tema do painel" onchange="window.BpoTheme.set(this.value)">
        <option value="system">Sistema</option>
        <option value="light">Claro</option>
        <option value="dark">Escuro</option>
    </select>
</label>
