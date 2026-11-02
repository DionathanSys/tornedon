import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

test('built worker initializes at the root and leaves server-rendered navigation on the network', () => {
    const listeners = new Map();
    const worker = {
        location: new URL('https://example.com/sw.js'),
        registration: { scope: 'https://example.com/' },
        addEventListener(name, callback) {
            listeners.set(name, [...(listeners.get(name) ?? []), callback]);
        },
        URL, Request, Response, Headers, console, setTimeout,
    };
    worker.self = worker;
    runInNewContext(readFileSync(new URL('../../public/build/sw.js', import.meta.url), 'utf8'), worker);

    assert.ok(listeners.has('install'));
    assert.ok(listeners.has('activate'));
    assert.ok(listeners.has('fetch'));

    for (const path of ['/operation', '/operation/login', '/operation/1', '/admin/login', '/livewire/update']) {
        let intercepted = false;
        const event = {
            request: { url: `https://example.com${path}`, method: 'GET', destination: 'document', mode: 'navigate' },
            respondWith() { intercepted = true; },
        };
        for (const callback of listeners.get('fetch')) callback(event);
        assert.equal(intercepted, false, `${path} must be rendered by Laravel`);
    }
});
