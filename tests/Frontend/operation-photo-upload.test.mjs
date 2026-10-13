import assert from 'node:assert/strict';
import test from 'node:test';
import { operationPhotoUpload, photoDimensions, prepareServicePhotos } from '../../resources/js/operation-photo-upload.js';

const mb = 1024 * 1024;
const photo = (size, type = 'image/jpeg') => ({ name: 'foto.jpg', size, type });

test('photo scaling preserves landscape and portrait proportions without upscaling', () => {
    assert.deepEqual(photoDimensions(4000, 3000), { width: 1920, height: 1440 });
    assert.deepEqual(photoDimensions(3000, 4000), { width: 1440, height: 1920 });
    assert.deepEqual(photoDimensions(800, 600), { width: 800, height: 600 });
});

test('large photo is reduced only after explicit consent, before upload', async () => {
    const original = photo(15 * mb);
    const reduced = photo(mb);
    let confirmed = false;
    const result = await prepareServicePhotos([original], {
        type: 'service_photo', maxBytes: 10 * mb,
        confirm: message => { assert.match(message, /Deseja reduzir/); confirmed = true; return true; },
        reduce: (file, maxBytes) => { assert.ok(confirmed); assert.equal(file, original); assert.equal(maxBytes, 5 * mb); return reduced; },
    });
    assert.deepEqual(result.files, [reduced]);
    assert.match(result.messages[0], /reduzida de 15.0 MB para 1.0 MB/);
    assert.equal(original.size, 15 * mb);
});

test('declining reduction keeps original within limit and skips oversized original', async () => {
    const withinLimit = photo(5.3 * mb);
    const result = await prepareServicePhotos([withinLimit, photo(15 * mb)], {
        type: 'service_photo', maxBytes: 10 * mb, confirm: () => false,
        reduce: () => assert.fail('Must not reduce without consent'),
    });
    assert.deepEqual(result.files, [withinLimit]);
    assert.match(result.messages[0], /não enviado.*10 MB/);
});

test('small photos and other attachment types are not recompressed', async () => {
    for (const [file, type] of [[photo(mb), 'service_photo'], [photo(8 * mb), 'generic']]) {
        const result = await prepareServicePhotos([file], {
            type, maxBytes: 10 * mb,
            confirm: () => assert.fail('Should not ask to reduce'), reduce: () => assert.fail('Should not reduce'),
        });
        assert.deepEqual(result.files, [file]);
    }
});

test('unsupported oversized image is skipped and compression failure is explained', async () => {
    const result = await prepareServicePhotos([photo(15 * mb, 'image/heic'), photo(15 * mb)], {
        type: 'service_photo', maxBytes: 10 * mb, confirm: () => true,
        reduce: () => { throw new Error('Falha na redução.'); },
    });
    assert.deepEqual(result.files, []);
    assert.match(result.messages[0], /não enviado/);
    assert.match(result.messages[1], /Falha na redução/);
});

test('file selection reaches Livewire and stays busy until the temporary upload finishes', async () => {
    const selected = photo(mb);
    const state = operationPhotoUpload({ service_photo: 10 * mb });
    let finish;
    let uploading;
    const started = new Promise(resolve => { uploading = resolve; });
    state.$wire = {
        attachmentType: 'service_photo',
        files: ['previous-selection'],
        async set(property, value) { this[property] = value; },
        uploadMultiple(property, files, onFinish, onError, onProgress) {
            assert.equal(property, 'files');
            assert.deepEqual(files, [selected]);
            assert.deepEqual(this.files, []);
            onProgress({ detail: { progress: 50 } });
            finish = () => { this.files = ['livewire-file:foto.jpg']; onFinish(this.files); };
            uploading();
        },
    };
    const input = { files: [selected], value: 'foto.jpg' };
    const selection = state.selectFiles({ target: input });
    await started;
    assert.equal(state.busy, true);
    assert.equal(state.progress, 50);
    finish();
    await selection;
    assert.equal(state.busy, false);
    assert.deepEqual(state.$wire.files, ['livewire-file:foto.jpg']);
    assert.equal(input.value, '');
});

test('temporary upload failure is visible and does not leave the form busy', async () => {
    const state = operationPhotoUpload({ service_photo: 10 * mb });
    state.$wire = {
        attachmentType: 'service_photo',
        async set() {},
        uploadMultiple(property, files, finish, error) { error(); },
    };
    await state.selectFiles({ target: { files: [photo(mb)], value: 'foto.jpg' } });
    assert.equal(state.busy, false);
    assert.match(state.messages[0], /Não foi possível enviar os arquivos/);
});
