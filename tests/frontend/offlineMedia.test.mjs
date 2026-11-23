import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import {
    cacheMediaPlaylists,
    cachedMediaPlaylists,
} from '../../resources/js/lib/browserDownloads.ts';

test('offline player falls back to the cached shell when a new media navigation cannot reach the server', async () => {
    const listeners = new Map();
    const shell = new Response('offline app');
    const worker = readFileSync(
        new URL('../../public/browser-downloads-sw.js', import.meta.url),
        'utf8',
    );
    runInNewContext(worker, {
        URL,
        Response,
        self: {
            location: { origin: 'https://roxifile.test' },
            addEventListener: (type, listener) => listeners.set(type, listener),
        },
        fetch: async () => {
            throw new TypeError('Network is offline');
        },
        caches: {
            open: async () => ({
                match: async (path) =>
                    path === '/build/resources/offline.html'
                        ? shell
                        : undefined,
            }),
        },
    });
    let result;
    listeners.get('fetch')({
        request: {
            method: 'GET',
            mode: 'navigate',
            url: 'https://roxifile.test/media',
        },
        respondWith: (response) => {
            result = response;
        },
    });
    assert.equal(await result, shell);
});

test('offline playlists persist in browser storage and are scoped to the active account and session', (context) => {
    const rows = new Map([['roxifile.browser-account', '7']]);
    Object.defineProperty(globalThis, 'localStorage', {
        configurable: true,
        value: {
            getItem: (key) => rows.get(key) ?? null,
            setItem: (key, value) => rows.set(key, value),
        },
    });
    context.after(() => {
        delete globalThis.localStorage;
    });
    const playlists = [{ id: 1, name: 'Saved mix', file_ids: [3, 5] }];
    cacheMediaPlaylists(7, playlists);
    assert.deepEqual(cachedMediaPlaylists(7), playlists);
    assert.deepEqual(cachedMediaPlaylists(8), []);
    rows.set('roxifile.browser-epoch.7', 'logged-out');
    assert.deepEqual(cachedMediaPlaylists(7), []);
    rows.set('roxifile.browser-retain.7', 'yes');
    assert.deepEqual(cachedMediaPlaylists(7), playlists);
});
