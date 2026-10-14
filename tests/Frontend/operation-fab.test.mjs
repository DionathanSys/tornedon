import test from 'node:test';
import assert from 'node:assert/strict';
import { fabBounds, clampFabPosition, normalizeFabPosition, restoreFabPosition, fabMenuPosition, operationFab } from '../../resources/js/operation-fab.js';

test('button stays inside the viewport and above navigation', () => {
    const bounds = fabBounds(390, 844, 56, 770);
    assert.deepEqual(clampFabPosition(-50, 900, bounds), { x: 8, y: 706 });
    assert.deepEqual(clampFabPosition(900, -50, bounds), { x: 326, y: 8 });
});

test('normalized placement survives resizing and orientation changes', () => {
    const original = fabBounds(390, 844, 56, 770);
    const position = normalizeFabPosition(167, 357, original);
    assert.deepEqual(position, { x: 0.5, y: 0.5 });
    const resized = fabBounds(844, 390, 56, 320);
    assert.deepEqual(restoreFabPosition(position, resized), { x: 394, y: 132 });
});

test('very small bounds do not yield invalid persisted coordinates', () => {
    const bounds = fabBounds(50, 50, 56, 20);
    assert.deepEqual(normalizeFabPosition(8, 8, bounds), { x: 0, y: 0 });
    assert.deepEqual(restoreFabPosition({ x: 0.5, y: 0.5 }, bounds), { x: 8, y: 8 });
});

test('safe areas keep the moved button away from notches and system gestures', () => {
    const bounds = fabBounds(390, 844, 56, 770, { top: 44, right: 30, bottom: 34, left: 0 });
    assert.deepEqual(clampFabPosition(900, -50, bounds), { x: 304, y: 44 });
});

test('menu opens above a low button and stays on screen near every edge', () => {
    assert.deepEqual(fabMenuPosition({ top: 700, bottom: 756, right: 368 }, 288, 200, 390, 770), { x: 80, y: 492 });
    assert.deepEqual(fabMenuPosition({ top: 8, bottom: 64, right: 64 }, 288, 200, 390, 770), { x: 8, y: 72 });
});

test('creation events distinguish migrated modules from service orders and requisitions', () => {
    for (const [config, expected] of [
        [{ kind: 'service-order' }, 'operation-create-record'],
        [{ kind: 'production_requests', event: 'operation-create-module' }, 'operation-create-module'],
    ]) {
        const fab = operationFab(config);
        let dispatched;
        fab.closeMenu = () => {};
        fab.$dispatch = (name, detail) => { dispatched = { name, detail }; };
        fab.activatePrimary();
        assert.deepEqual(dispatched, { name: expected, detail: { kind: config.kind } });
    }
});
