<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="cpw" wire:key="permission-workbench-{{ $getKey() }}-{{ md5(json_encode($getPermissionGroups())) }}" x-data="{{ file_get_contents(resource_path('js/company-permission-workbench.js')) }}({
        state: $wire.entangle(@js($getStatePath())),
        groups: @js($getPermissionGroups()),
        readOnly: @js($isDisabled()),
        delegation: @js($isDelegation()),
    })">
        <div class="cpw-toolbar">
            <label class="cpw-search"><x-filament::icon icon="heroicon-o-magnifying-glass" /><span class="cpw-sr-only">Buscar módulo ou ação</span><input type="search" x-model="search" placeholder="Buscar módulo ou ação…" autocomplete="off" /></label>
            <label class="cpw-enabled"><input type="checkbox" x-model="enabledOnly" /><span x-text="delegation ? 'Só delegáveis' : 'Só permitidas'"></span></label>
            <span class="cpw-total" aria-live="polite"><strong x-text="totalEnabled"></strong> <span x-text="delegation ? 'ações delegáveis' : 'ações permitidas'"></span></span>
        </div>
        <div class="cpw-layout">
            <nav class="cpw-modules" aria-label="Módulos de permissões">
                <template x-for="group in filteredGroups" :key="group.key">
                    <button type="button" class="cpw-module" :class="{ 'is-active': activeGroup?.key === group.key }" :aria-pressed="activeGroup?.key === group.key" @click="activeKey = group.key">
                        <span><small x-text="group.area"></small><strong x-text="group.label"></strong></span><span class="cpw-count" :class="{ 'has-grants': count(group) > 0 }" x-text="count(group)"></span>
                    </button>
                </template>
                <p class="cpw-empty" x-show="!filteredGroups.length" x-cloak>Nenhum módulo encontrado. Ajuste a busca ou o filtro.</p>
            </nav>
            <section class="cpw-editor" aria-label="Ações e alcance" x-show="activeGroup">
                <div class="cpw-module-heading"><div><span class="cpw-eyebrow" x-text="activeGroup?.area"></span><h3 x-text="activeGroup?.label"></h3><p x-text="activeGroup?.description"></p></div><x-filament::icon icon="heroicon-o-adjustments-horizontal" /></div>
                <div class="cpw-presets" x-show="!readOnly">
                    <span>Configurar este módulo:</span>
                    <button type="button" @click="setModule('view')" x-show="!delegation && activeGroup?.permissions.some(permission => permission.key.endsWith('.view'))">Somente consulta</button>
                    <button type="button" @click="setModule('all')" x-text="delegation ? 'Permitir delegação de todas' : 'Permitir todas as ações'"></button>
                    <button type="button" @click="setModule('none')">Bloquear todas</button>
                </div>
                <div class="cpw-column-heading"><span>Ação</span><span x-text="delegation ? 'Delegação para auxiliares' : 'O que a pessoa pode acessar'"></span></div>
                <div class="cpw-permissions">
                    <template x-for="permission in activePermissions" :key="permission.key">
                        <div class="cpw-permission" :class="{ 'is-enabled': isEnabled(permission.key) }">
                            <span class="cpw-indicator" aria-hidden="true" x-text="isEnabled(permission.key) ? '✓' : '−'"></span>
                            <div class="cpw-action"><strong x-text="permission.label"></strong><p x-text="permission.description"></p></div>
                            <span class="cpw-scope-value" x-show="readOnly" x-text="scopeLabel(permission)"></span>
                            <select x-show="!readOnly" :disabled="readOnly" :aria-label="(activeGroup?.label || '') + ': ' + permission.label" :value="state[permission.key] || 'none'" @change="setPermission(permission, $event.target.value)">
                                <template x-for="[value, label] in Object.entries(permission.options)" :key="value"><option :value="value" x-text="label" :selected="(state[permission.key] || 'none') === value"></option></template>
                            </select>
                        </div>
                    </template>
                </div>
            </section>
            <div class="cpw-empty cpw-empty-editor" x-show="!activeGroup" x-cloak><x-filament::icon icon="heroicon-o-magnifying-glass" /><strong x-text="delegation && !groups.length ? 'Nenhuma ação disponível para delegação' : 'Nenhuma ação para exibir'"></strong><span x-text="delegation && !groups.length ? 'Selecione os papéis do responsável. Apenas ações com alcance de empresa podem ser autorizadas para auxiliares.' : 'Limpe a busca ou desmarque o filtro para ver os módulos disponíveis.'"></span></div>
        </div>
        <footer class="cpw-footer"><x-filament::icon icon="heroicon-o-information-circle" /><p x-text="delegation
            ? 'O auxiliar recebe apenas as ações autorizadas pela empresa e que o responsável BPO pode delegar. Encerrar o atendimento remove os acessos concedidos por ele.'
            : 'Próprios = criados pelo usuário. Atribuídos = sob sua responsabilidade. Todos = somente os registros desta empresa. Quando a pessoa tem mais de um papel, as permissões se somam.'"></p></footer>
    </div>
    @once @include('forms.components.company-permission-workbench-styles') @endonce
</x-dynamic-component>
