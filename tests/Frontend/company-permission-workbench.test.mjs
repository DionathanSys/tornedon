import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/company-permission-workbench.js', import.meta.url), 'utf8');
const factory = vm.runInNewContext(source);
const groups = [
    { key: 'quotes', label: 'Orçamentos', area: 'Vendas', description: 'Orçamentos enviados aos clientes.', permissions: [
        { key: 'quotes.view', label: 'Consultar', description: 'Abrir registros.', options: { none: 'Não permitido', own: 'Somente próprios', company: 'Todos da empresa' } },
        { key: 'quotes.create', label: 'Criar', description: 'Cadastrar registros.', options: { none: 'Não permitido', company: 'Permitido' } },
        { key: 'quotes.update', label: 'Alterar', description: 'Editar registros.', options: { none: 'Não permitido', own: 'Somente próprios', company: 'Todos da empresa' } },
    ] },
    { key: 'service_orders', label: 'Ordens de serviço', area: 'Atendimento', description: 'Responsáveis pelas OS.', permissions: [
        { key: 'service_orders.view', label: 'Consultar', description: 'Abrir atendimentos.', options: { none: 'Não permitido', own: 'Somente próprios', assigned: 'Somente atribuídos', company: 'Todos da empresa' } },
    ] },
];

function workbench(config = {}) {
    const component = factory({ groups, state: {}, ...config });
    component.init();
    return component;
}

test('normaliza o estado do formulário sem perder permissões existentes de outros módulos', () => {
    const component = workbench({ state: { quotes: { view: 'own' }, 'other.permission': 'company' } });
    assert.equal(component.state['quotes.view'], 'own');
    assert.equal(component.state['other.permission'], 'company');
    assert.equal(component.totalEnabled, 1);
    component.setPermission(groups[0].permissions[2], 'own');
    assert.equal(component.state['quotes.update'], 'own');
    assert.equal(component.state['quotes.view'], 'own');
    component.setPermission(groups[0].permissions[2], 'assigned');
    assert.equal(component.state['quotes.update'], 'own');
});

test('busca sem acentos encontra módulos e ações e mantém seleção válida ao filtrar', () => {
    const component = workbench();
    component.search = 'orcamento';
    assert.equal(component.activeGroup.key, 'quotes');
    assert.equal(component.activePermissions.length, 3);
    component.search = 'cadastrar';
    assert.equal(component.activePermissions.length, 1);
    assert.equal(component.activePermissions[0].key, 'quotes.create');
    component.search = 'atendimento';
    assert.equal(component.activeGroup.key, 'service_orders');
    component.search = 'inexistente';
    assert.equal(component.activeGroup, null);
    assert.equal(component.activePermissions.length, 0);
});

test('atalhos aplicam somente consulta ou todas as ações ao módulo escolhido', () => {
    const component = workbench({ state: { 'quotes.cancel': 'company', 'service_orders.view': 'assigned' } });
    component.activeKey = 'quotes';
    component.setModule('view');
    assert.equal(component.state['quotes.view'], 'company');
    assert.equal(component.state['quotes.cancel'], undefined);
    assert.equal(component.state['service_orders.view'], 'assigned');
    assert.equal(component.count(groups[0]), 1);
    component.setModule('all');
    assert.equal(component.count(groups[0]), 3);
    component.setModule('none');
    assert.equal(component.count(groups[0]), 0);
    assert.equal(component.state['service_orders.view'], 'assigned');
});

test('consulta de permissões resultantes explica a soma dos alcances e não permite edição', () => {
    const component = workbench({ readOnly: true, state: { 'service_orders.view': ['own', 'assigned'], 'quotes.view': ['own', 'company'] } });
    assert.equal(component.scopeLabel(groups[1].permissions[0]), 'Próprios ou atribuídos');
    assert.equal(component.scopeLabel(groups[0].permissions[0]), 'Todos da empresa');
    component.setPermission(groups[0].permissions[0], 'none');
    component.setModule('none');
    assert.equal(component.totalEnabled, 2);
});

test('delegação descarta ações que não fazem parte da autorização exibida', () => {
    const component = workbench({ delegation: true, groups: [groups[0]], state: { 'quotes.view': 'company', 'quotes.update': 'own', 'service_orders.view': 'company' } });
    assert.equal(component.state['quotes.view'], 'company');
    assert.equal(component.state['quotes.update'], undefined);
    assert.equal(component.state['service_orders.view'], undefined);
    component.enabledOnly = true;
    assert.equal(component.activePermissions.length, 1);
    component.setModule('none');
    assert.equal(component.activeGroup, null);
});
