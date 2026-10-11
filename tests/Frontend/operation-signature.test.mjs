import test from 'node:test';
import assert from 'node:assert/strict';
import { signatureLayout, signaturePoint } from '../../resources/js/operation-signature.js';

test('portrait capture uses the long side as PNG width', () => {
    assert.deepEqual(signatureLayout(334, 844), { rotated: true, width: 844, height: 334 });
});

test('landscape capture stays horizontal', () => {
    assert.deepEqual(signatureLayout(844, 334), { rotated: false, width: 844, height: 334 });
});

test('resize fits the same bitmap aspect ratio without stretching or clipping', () => {
    const ratio = 844 / 334;
    const resized = signatureLayout(1200, 600, ratio);
    assert.ok(resized.width <= 1200 && resized.height <= 600);
    assert.ok(Math.abs(resized.width / resized.height - ratio) < 0.000001);
    const returned = signatureLayout(334, 844, ratio);
    assert.equal(returned.width, 844);
    assert.equal(returned.height, 334);
});

test('rotated corners map to the horizontal bitmap, with the base on the right', () => {
    const rect = { left: 10, top: 20, width: 300, height: 800 };
    assert.deepEqual(signaturePoint(10, 820, rect, 1600, 600, true), { x: 0, y: 0 });
    assert.deepEqual(signaturePoint(10, 20, rect, 1600, 600, true), { x: 1600, y: 0 });
    assert.deepEqual(signaturePoint(310, 20, rect, 1600, 600, true), { x: 1600, y: 600 });
    assert.deepEqual(signaturePoint(310, 820, rect, 1600, 600, true), { x: 0, y: 600 });
    assert.deepEqual(signaturePoint(160, 420, rect, 1600, 600, true), { x: 800, y: 300 });
});

test('normal coordinates and captured pointers outside the area are bounded', () => {
    const rect = { left: 10, top: 20, width: 800, height: 300 };
    assert.deepEqual(signaturePoint(410, 170, rect, 1600, 600, false), { x: 800, y: 300 });
    assert.deepEqual(signaturePoint(-50, 1000, rect, 1600, 600, false), { x: 0, y: 600 });
});
