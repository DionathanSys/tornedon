import test from 'node:test';
import assert from 'node:assert/strict';
import { createOperationPwaInstaller } from '../../resources/js/operation-pwa.js';

function setup({ ios = false, standalone = false, secure = true } = {}) {
    const browser = new EventTarget();
    const displayMode = new EventTarget();
    displayMode.matches = standalone;
    Object.assign(browser, {
        matchMedia: () => displayMode,
        navigator: { userAgent: ios ? 'iPhone' : 'Chrome', platform: '', maxTouchPoints: 0 },
        isSecureContext: secure,
        console,
    });
    const factory = createOperationPwaInstaller(browser);
    const component = factory();
    component.init();
    return { browser, component, factory, displayMode };
}

function offerInstall(browser, outcome = 'accepted') {
    const event = new Event('beforeinstallprompt', { cancelable: true });
    let calls = 0;
    event.prompt = async () => { calls++; };
    event.userChoice = Promise.resolve({ outcome });
    browser.dispatchEvent(event);
    assert.equal(event.defaultPrevented, true);
    return () => calls;
}

test('native installation is only offered when the browser makes it available', async () => {
    const { browser, component } = setup();
    assert.equal(component.available, false);
    const calls = offerInstall(browser);
    assert.equal(component.available, true);
    await component.install();
    assert.equal(calls(), 1);
    assert.equal(component.available, false);
    await component.install();
    assert.equal(calls(), 1);
});

test('declined prompts are consumed and a later offer can be used', async () => {
    const { browser, component } = setup();
    offerInstall(browser, 'dismissed');
    await component.install();
    assert.equal(component.available, false);
    offerInstall(browser);
    assert.equal(component.available, true);
});

test('a prompt received before Alpine mounts survives navigation and dismissal', () => {
    const { browser, component, factory } = setup();
    offerInstall(browser);
    component.dismiss();
    component.destroy();
    const nextPage = factory();
    nextPage.init();
    assert.equal(nextPage.available, true);
    assert.equal(nextPage.dismissed, true);
});

test('iOS provides manual instructions which can be reopened from the menu', async () => {
    const { component } = setup({ ios: true });
    assert.equal(component.available, true);
    await component.install();
    assert.equal(component.instructions, true);
    component.dismiss();
    await component.install();
    assert.equal(component.dismissed, false);
    assert.equal(component.instructions, true);
});

test('installed applications and insecure contexts do not offer installation', () => {
    for (const options of [{ standalone: true, ios: true }, { secure: false, ios: true }]) {
        assert.equal(setup(options).component.available, false);
    }
    const { browser, component } = setup();
    offerInstall(browser);
    browser.dispatchEvent(new Event('appinstalled'));
    assert.equal(component.available, false);
});

test('display mode changes hide installation on existing components', () => {
    const { browser, component, displayMode } = setup();
    offerInstall(browser);
    displayMode.matches = true;
    displayMode.dispatchEvent(new Event('change'));
    assert.equal(component.available, false);
});

test('failed prompts are reported and do not leave installation locked', async () => {
    const { browser, component } = setup();
    let reported;
    browser.console = { error: (...args) => { reported = args; } };
    const event = new Event('beforeinstallprompt', { cancelable: true });
    event.prompt = async () => { throw new Error('prompt unavailable'); };
    browser.dispatchEvent(event);
    await component.install();
    assert.equal(component.installing, false);
    assert.equal(component.available, false);
    assert.equal(reported[1].message, 'prompt unavailable');
});
