import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/bank-reconciliation-workbench.js', import.meta.url), 'utf8');
const factory = vm.runInNewContext(source);

function workbench(overrides = {}) {
    return factory({
        state: { type: 'receivable', items: [], exception_reason: '' },
        bank: { valid: true, inflow: true, total_cents: 100000, lines: [] },
        searchRecords: async () => ({ records: [], has_more: false }),
        submit: async () => {},
        ...overrides,
    });
}

const record = (id, amount_cents, extra = {}) => ({ id, amount_cents, description: 'Conta ' + id, ...extra });

test('seleciona duas contas, limita a segunda ao restante e explica o saldo em aberto', () => {
    const component = workbench();
    component.toggle(record(1, 70000));
    assert.equal(component.difference, 30000);
    assert.equal(component.canSubmit, false);
    component.toggle(record(2, 50000));
    assert.equal(component.items[1].amount, 300);
    assert.equal(component.difference, 0);
    assert.equal(component.canSubmit, true);
    component.toggle(record(1, 70000));
    assert.equal(component.items.length, 1);
    assert.equal(component.canSubmit, false);
    assert.match(component.status, /Faltam/);
});

test('edita baixa com moeda brasileira, preenche restante e bloqueia valor acima do saldo', () => {
    const component = workbench();
    component.toggle(record(1, 150000));
    component.updateAmount(1, '1.200,00');
    assert.equal(component.selectedTotal, 120000);
    assert.match(component.status, /excedeu/);
    component.fillRemaining(1);
    assert.equal(component.items[0].amount, 1000);
    assert.equal(component.canSubmit, true);
    component.updateAmount(1, '1.600,00');
    assert.equal(component.validAmounts, false);
    component.updateAmount(1, 'inválido');
    assert.equal(component.validAmounts, false);
});

test('vínculos existentes preservam o valor integral e pedem motivo apenas quando necessário', () => {
    const component = workbench({ state: { type: 'cash_movement', items: [], exception_reason: '' } });
    component.toggle(record(1, 60000));
    component.toggle(record(2, 40000, { date_exception: true }));
    assert.equal(component.needsReason, true);
    assert.equal(component.canSubmit, false);
    component.state.exception_reason = 'Lançado depois pelo banco';
    assert.ok(component.canSubmit);
    component.remove(2);
    assert.equal(component.needsReason, false);
    assert.equal(component.difference, 40000);
});

test('troca de modo limpa seleção e resposta atrasada não substitui a busca atual', async () => {
    const requests = [];
    const component = workbench({ searchRecords: (type, search, page) => new Promise(resolve => requests.push({ type, search, page, resolve })) });
    component.toggle(record(1, 100000));
    const old = component.load();
    component.changeType('cash_movement');
    assert.equal(component.items.length, 0);
    requests[1].resolve({ records: [record(2, 100000)], has_more: false });
    await Promise.resolve();
    requests[0].resolve({ records: [record(1, 100000)], has_more: true });
    await old;
    assert.equal(component.results[0].id, 2);
    assert.equal(component.hasMore, false);
    assert.equal(component.loading, false);
});

test('paginação mantém os selecionados e falha de busca permite nova tentativa', async () => {
    let fail = false;
    const component = workbench({ searchRecords: async (type, search, page) => {
        if (fail) throw new Error('network');
        return { records: [record(page, 50000)], has_more: page === 1 };
    } });
    await component.load();
    component.toggle(component.results[0]);
    await component.load(true);
    assert.equal(component.results.length, 2);
    assert.equal(component.items.length, 1);
    fail = true;
    await component.load();
    assert.match(component.error, /Tente novamente/);
    assert.equal(component.items.length, 1);
    fail = false;
    await component.load();
    assert.equal(component.error, '');
});

test('confirma apenas seleção válida e impede envio duplicado', async () => {
    let calls = 0;
    let resolve;
    const component = workbench({ submit: () => { calls++; return new Promise(done => { resolve = done; }); } });
    await component.submit();
    assert.equal(calls, 0);
    component.toggle(record(1, 100000));
    const pending = component.submit();
    await component.submit();
    assert.equal(calls, 1);
    resolve();
    await pending;
    assert.equal(component.submitting, false);
});
