import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/bpo-theme.js', import.meta.url), 'utf8');
const factory = vm.runInNewContext(source);

function environment({ stored = null, dark = false, blocked = false } = {}) {
    const classes = new Set();
    const root = { classList: { toggle(name, enabled) { enabled ? classes.add(name) : classes.delete(name); } }, dataset: {}, style: {} };
    const media = { matches: dark, listeners: [], addEventListener(event, callback) { this.listeners.push(callback); } };
    const controls = [{ value: '' }];
    const emitted = [];
    const storage = { value: stored };
    const theme = factory({ root, media,
        read: () => { if (blocked) throw new Error('storage disabled'); return storage.value; },
        write: value => { if (blocked) throw new Error('storage disabled'); storage.value = value; },
        selects: () => controls, emit: value => emitted.push(value),
    });
    const system = matches => { media.matches = matches; media.listeners.forEach(listener => listener()); };
    return { theme, root, classes, media, controls, emitted, storage, system };
}

test('aplica a preferência salva imediatamente e sincroniza os seletores', () => {
    const env = environment({ stored: 'dark' });
    env.theme.init();
    assert.ok(env.classes.has('dark'));
    assert.equal(env.root.style.colorScheme, 'dark');
    assert.equal(env.root.dataset.bpoTheme, 'dark');
    assert.equal(env.controls[0].value, 'dark');
    env.controls.push({ value: '' });
    env.theme.apply();
    assert.equal(env.controls[1].value, 'dark');
});

test('trocar o tema persiste a escolha e comunica a mudança ao Filament', () => {
    const env = environment();
    env.theme.init();
    env.theme.set('dark');
    assert.equal(env.storage.value, 'dark');
    assert.ok(env.classes.has('dark'));
    assert.equal(env.emitted.at(-1), 'dark');
    env.theme.set('light');
    assert.equal(env.storage.value, 'light');
    assert.equal(env.classes.has('dark'), false);
    assert.equal(env.root.dataset.theme, 'light');
});

test('modo sistema acompanha o dispositivo e modo explícito não é sobrescrito', () => {
    const env = environment();
    env.theme.init();
    assert.equal(env.storage.value, 'system');
    env.system(true);
    assert.ok(env.classes.has('dark'));
    assert.equal(env.controls[0].value, 'system');
    env.system(false);
    assert.equal(env.classes.has('dark'), false);
    env.theme.set('light');
    env.system(true);
    assert.equal(env.classes.has('dark'), false);
});

test('restaura mudanças de outra aba e não gera ciclo ao receber eventos de tema', () => {
    const env = environment();
    env.theme.init();
    env.storage.value = 'dark';
    env.theme.restore();
    assert.ok(env.classes.has('dark'));
    const count = env.emitted.length;
    env.theme.accept('light');
    assert.equal(env.classes.has('dark'), false);
    assert.equal(env.emitted.length, count);
});

test('preferência inválida ou armazenamento indisponível não bloqueia a interface', () => {
    const invalid = environment({ stored: 'invalid', dark: true });
    invalid.theme.init();
    assert.equal(invalid.storage.value, 'system');
    assert.ok(invalid.classes.has('dark'));
    const blocked = environment({ blocked: true });
    assert.doesNotThrow(() => blocked.theme.init());
    blocked.theme.set('dark');
    assert.ok(blocked.classes.has('dark'));
});

test('reinicialização não acumula listeners de mudança do sistema', () => {
    const env = environment();
    env.theme.init();
    env.theme.set('dark');
    env.theme.init();
    assert.equal(env.media.listeners.length, 1);
    assert.equal(env.root.dataset.theme, 'dark');
});
