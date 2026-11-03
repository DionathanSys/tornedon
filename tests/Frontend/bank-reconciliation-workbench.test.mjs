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

test('juros e multa ajustam a sugestão até o valor do banco e permitem quitação acima do saldo original', () => {
    const component = workbench({ bank: { valid: true, inflow: true, total_cents: 112000 } });
    component.toggle(record(1, 100000));
    component.updateCharge(1, 'interest_amount', '100,00');
    component.updateCharge(1, 'fine_amount', '50,00');
    component.updateCharge(1, 'discount_amount', '30,00');
    assert.equal(component.adjustedBalance(component.items[0]), 112000);
    assert.equal(component.items[0].amount, 1120);
    assert.equal(component.remainingBalance(component.items[0]), 0);
    assert.equal(component.hasAdjustments(component.items[0]), true);
    assert.equal(component.canSubmit, true);
});

test('desconto quita a parcela sem exigir o valor original e valor parcial manual é preservado', () => {
    const component = workbench({ bank: { valid: true, inflow: true, total_cents: 90000 } });
    component.toggle(record(1, 100000));
    component.updateCharge(1, 'discount_amount', '100,00');
    assert.equal(component.items[0].amount, 900);
    assert.equal(component.remainingBalance(component.items[0]), 0);
    assert.equal(component.canSubmit, true);
    component.updateAmount(1, '600,00');
    component.updateCharge(1, 'interest_amount', '200,00');
    assert.equal(component.items[0].amount, 600);
    assert.equal(component.remainingBalance(component.items[0]), 50000);
    component.fillRemaining(1);
    assert.equal(component.items[0].amount, 900);
    assert.equal(component.remainingBalance(component.items[0]), 20000);
});

test('encargos inválidos e descontos excessivos bloqueiam confirmação; movimentos existentes não recebem ajustes', () => {
    const component = workbench();
    component.toggle(record(1, 100000));
    component.updateCharge(1, 'interest_amount', '-1');
    assert.equal(component.validAmounts, false);
    component.updateCharge(1, 'interest_amount', '0');
    assert.equal(component.validAmounts, true);
    component.updateCharge(1, 'discount_amount', '1.001,00');
    assert.equal(component.validAmounts, false);
    assert.equal(component.canSubmit, false);
    component.changeType('cash_movement');
    component.toggle(record(1, 100000));
    component.updateCharge(1, 'interest_amount', '100,00');
    assert.equal(component.items[0].interest_amount, 0);
    assert.equal(component.adjustedBalance(component.items[0]), 100000);
});

test('carrega sugestões e busca manual ao abrir, sem selecionar registros automaticamente', async () => {
    let searches = 0;
    let suggestions = 0;
    const component = workbench({
        searchRecords: async () => { searches++; return { records: [], has_more: false }; },
        loadSuggestions: async () => { suggestions++; return { records: [record(1, 100000, { type: 'receivable' })], failed_lines: 0 }; },
    });
    component.init();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(searches, 1);
    assert.equal(suggestions, 1);
    assert.equal(component.suggestions.length, 1);
    assert.equal(component.items.length, 0);
    assert.equal(component.canSubmit, false);
});

test('seleciona sugestão de outra origem e distingue IDs iguais de movimentos e parcelas', () => {
    const component = workbench({ state: { type: 'cash_movement', items: [], exception_reason: '' } });
    const installment = record(1, 100000, { type: 'receivable' });
    const movement = record(1, 100000, { type: 'cash_movement' });
    component.selectSuggestion(installment);
    assert.equal(component.state.type, 'receivable');
    assert.equal(component.items[0].amount, 1000);
    assert.equal(component.isSuggestionSelected(installment), true);
    assert.equal(component.isSuggestionSelected(movement), false);
    assert.equal(component.canSelectSuggestion(movement), false);
    component.selectSuggestion(movement);
    assert.equal(component.state.type, 'receivable');
    assert.equal(component.items[0].id, 1);
    component.selectSuggestion(installment);
    assert.equal(component.items.length, 0);
    component.selectSuggestion(movement);
    assert.equal(component.state.type, 'cash_movement');
    assert.equal(component.isSuggestionSelected(movement), true);
    assert.equal(component.needsReason, false);
});

test('falha de sugestões preserva busca e seleção, permite recuperação e reporta falha parcial', async () => {
    let failure = true;
    const component = workbench({
        searchRecords: async () => ({ records: [record(1, 100000)], has_more: false }),
        loadSuggestions: async () => {
            if (failure) throw new Error('network');
            return { records: [record(2, 90000, { type: 'receivable' })], failed_lines: 1 };
        },
    });
    await component.load();
    component.toggle(component.results[0]);
    await component.loadSuggestions();
    assert.match(component.suggestionsError, /busca abaixo/);
    assert.equal(component.results[0].id, 1);
    assert.equal(component.items[0].id, 1);
    assert.equal(component.canSubmit, true);
    failure = false;
    await component.loadSuggestions();
    assert.equal(component.suggestionsError, '');
    assert.equal(component.suggestions.length, 1);
    assert.match(component.suggestionsWarning, /1 lançamento/);
    assert.equal(component.items[0].id, 1);
});

test('resposta atrasada de sugestões não substitui a atualização mais recente', async () => {
    const requests = [];
    const component = workbench({ loadSuggestions: () => new Promise(resolve => requests.push(resolve)) });
    const old = component.loadSuggestions();
    const current = component.loadSuggestions();
    requests[1]({ records: [record(2, 100000, { type: 'receivable' })], failed_lines: 0 });
    await current;
    requests[0]({ records: [record(1, 100000, { type: 'receivable' })], failed_lines: 1 });
    await old;
    assert.equal(component.suggestions[0].id, 2);
    assert.equal(component.suggestionsWarning, '');
    assert.equal(component.suggestionsLoading, false);
});

test('seleciona uma composição completa e preserva a seleção diante de outra proposta', () => {
    const component = workbench();
    const bundle = { type: 'receivable', kind: 'combination', items: [
        record(1, 60000, { suggested_amount: 600 }), record(2, 40000, { suggested_amount: 400 }),
    ] };
    component.selectBundle(bundle);
    assert.equal(component.items.length, 2);
    assert.equal(component.selectedTotal, 100000);
    assert.equal(component.canSubmit, true);
    component.selectBundle({ type: 'cash_movement', kind: 'aggregate', items: [record(3, 100000, { suggested_amount: 1000 })] });
    assert.equal(component.state.type, 'receivable');
    assert.equal(component.items.length, 2);
});

test('baixa parcial sugerida mantém saldo restante sem aplicar desconto', () => {
    const component = workbench();
    component.selectBundle({ type: 'receivable', kind: 'partial', items: [record(1, 200000, { suggested_amount: 1000 })] });
    assert.equal(component.items[0].discount_amount, 0);
    assert.equal(component.remainingBalance(component.items[0]), 100000);
    assert.equal(component.pendingAdjustments, false);
    assert.equal(component.canSubmit, true);
});

test('proposta de acréscimo exige definir juros e multa que expliquem a diferença', () => {
    const component = workbench({ bank: { valid: true, inflow: true, total_cents: 112000, lines: [] } });
    component.selectBundle({ type: 'receivable', kind: 'adjustment', items: [record(1, 100000, { suggested_amount: 1120, adjustment_expected_cents: 12000 })] });
    assert.equal(component.items[0].open_adjustments, true);
    assert.equal(component.canSubmit, false);
    assert.match(component.status, /Defina juros/);
    component.updateCharge(1, 'interest_amount', '100,00');
    component.updateCharge(1, 'fine_amount', '50,00');
    assert.equal(component.canSubmit, false);
    component.updateCharge(1, 'discount_amount', '30,00');
    assert.equal(component.pendingAdjustments, false);
    assert.equal(component.canSubmit, true);
    assert.equal(component.remainingBalance(component.items[0]), 0);
});

test('proposta de desconto não pode ser confirmada acidentalmente como baixa parcial', () => {
    const component = workbench({ bank: { valid: true, inflow: true, total_cents: 95000, lines: [] } });
    component.selectBundle({ type: 'receivable', kind: 'adjustment', items: [record(1, 100000, { suggested_amount: 950, adjustment_expected_cents: -5000 })] });
    assert.equal(component.difference, 0);
    assert.equal(component.canSubmit, false);
    component.updateCharge(1, 'discount_amount', '50,00');
    assert.equal(component.canSubmit, true);
    assert.equal(component.remainingBalance(component.items[0]), 0);
});

test('carrega propostas avançadas e informa limites sem selecionar nada automaticamente', async () => {
    const component = workbench({ loadSuggestions: async () => ({
        records: [], failed_lines: 0, advanced_truncated: true,
        bundles: [{ key: 'receivable:combination:1-2', type: 'receivable', kind: 'combination', items: [record(1, 50000, { suggested_amount: 500 }), record(2, 50000, { suggested_amount: 500 })] }],
    }) });
    await component.loadSuggestions();
    assert.equal(component.bundles.length, 1);
    assert.equal(component.items.length, 0);
    assert.match(component.suggestionsWarning, /limitada aos candidatos/);
});

test('mantém o identificador da consulta ao escolher outra origem ou composição', async () => {
    const component = workbench({ loadSuggestions: async () => ({ records: [], bundles: [], failed_lines: 0, evaluation_token: 'consulta-123' }) });
    await component.loadSuggestions();
    component.changeType('cash_movement');
    assert.equal(component.state.evaluation_token, 'consulta-123');
    component.selectBundle({ type: 'receivable', kind: 'partial', items: [record(1, 200000, { suggested_amount: 1000 })] });
    assert.equal(component.state.evaluation_token, 'consulta-123');
    assert.equal(component.items.length, 1);
});

test('registra rejeição explícita uma vez e preserva a seleção financeira', async () => {
    let calls = 0;
    let finish;
    const component = workbench({ rejectSuggestions: token => {
        assert.equal(token, 'consulta-123');
        calls++;
        return new Promise(resolve => { finish = resolve; });
    } });
    component.state.evaluation_token = 'consulta-123';
    component.toggle(record(1, 100000));
    const pending = component.rejectSuggestions();
    await component.rejectSuggestions();
    assert.equal(calls, 1);
    finish();
    await pending;
    await component.rejectSuggestions();
    assert.equal(calls, 1);
    assert.equal(component.suggestionsRejected, true);
    assert.equal(component.items[0].id, 1);
    assert.equal(component.canSubmit, true);
});

test('falha ao registrar avaliação permite nova tentativa sem impedir conciliação', async () => {
    let fail = true;
    const component = workbench({ rejectSuggestions: async () => { if (fail) throw new Error('network'); } });
    component.state.evaluation_token = 'consulta-123';
    component.toggle(record(1, 100000));
    await component.rejectSuggestions();
    assert.match(component.feedbackError, /Atualize as sugestões/);
    assert.equal(component.canSubmit, true);
    fail = false;
    await component.rejectSuggestions();
    assert.equal(component.feedbackError, '');
    assert.equal(component.suggestionsRejected, true);
});
