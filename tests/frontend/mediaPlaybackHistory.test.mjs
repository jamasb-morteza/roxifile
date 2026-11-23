import assert from 'node:assert/strict';
import test from 'node:test';

void test('queues playback once per event and keeps separate accounts isolated', async () => {
    const { pendingMediaPlayback, queueMediaPlayback } =
        await import('../../resources/js/lib/mediaPlaybackHistory.ts');
    const values = new Map();
    const storage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
    };

    queueMediaPlayback(storage, 4, { fileId: 12, eventId: 'event-1' });
    queueMediaPlayback(storage, 4, { fileId: 12, eventId: 'event-1' });
    queueMediaPlayback(storage, 5, { fileId: 12, eventId: 'event-2' });

    assert.deepEqual(pendingMediaPlayback(storage, 4), [
        { fileId: 12, eventId: 'event-1' },
    ]);
    assert.deepEqual(pendingMediaPlayback(storage, 5), [
        { fileId: 12, eventId: 'event-2' },
    ]);
});

void test('retains failed syncs and removes events after the server accepts them', async () => {
    const { pendingMediaPlayback, queueMediaPlayback, syncMediaPlayback } =
        await import('../../resources/js/lib/mediaPlaybackHistory.ts');
    const values = new Map([['roxifile.browser-account', '4']]);
    const storage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
    };
    queueMediaPlayback(storage, 4, { fileId: 12, eventId: 'event-1' });
    const previousDocument = globalThis.document;
    const previousFetch = globalThis.fetch;
    globalThis.document = { cookie: 'XSRF-TOKEN=test-token' };
    let status = 503;
    globalThis.fetch = async (url, options) => {
        assert.equal(url, '/media/tracks/12/playback');
        assert.equal(JSON.parse(options.body).event_id, 'event-1');
        return { ok: status === 204 };
    };
    try {
        assert.deepEqual(
            await syncMediaPlayback(
                storage,
                4,
                (id) => '/media/tracks/' + id + '/playback',
            ),
            [],
        );
        assert.equal(pendingMediaPlayback(storage, 4).length, 1);
        status = 204;
        assert.deepEqual(
            await syncMediaPlayback(
                storage,
                4,
                (id) => '/media/tracks/' + id + '/playback',
            ),
            [12],
        );
        assert.deepEqual(pendingMediaPlayback(storage, 4), []);
    } finally {
        globalThis.document = previousDocument;
        globalThis.fetch = previousFetch;
    }
});
