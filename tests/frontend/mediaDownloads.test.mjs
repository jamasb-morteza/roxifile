import assert from 'node:assert/strict';
import test from 'node:test';

void test('batch saves each requested track once, skips complete copies and continues after file failures', async () => {
    const { downloadMediaBatch } =
        await import('../../resources/js/lib/mediaDownloads.ts');
    const attempted = [];
    const updates = [];
    const result = await downloadMediaBatch(
        [8, 5, 8, 3, 6],
        new Set([5]),
        new AbortController().signal,
        async (id, _signal, progress) => {
            attempted.push(id);
            if (id === 3) throw new Error('Original unavailable');
            progress(8, 10);
        },
        (update) => updates.push(update),
    );

    assert.deepEqual(attempted, [8, 3, 6]);
    assert.deepEqual(result.saved, [8, 6]);
    assert.deepEqual(result.failures, [
        { id: 3, message: 'Original unavailable' },
    ]);
    assert.equal(result.cancelled, false);
    assert.deepEqual(updates.at(-1), {
        id: 6,
        position: 3,
        count: 3,
        bytes: 8,
        total: 10,
    });
});

void test('cancelling a batch leaves completed downloads and stops before the next file', async () => {
    const { downloadMediaBatch } =
        await import('../../resources/js/lib/mediaDownloads.ts');
    const controller = new AbortController();
    const attempted = [];
    const result = await downloadMediaBatch(
        [1, 2, 3],
        new Set(),
        controller.signal,
        async (id, signal) => {
            attempted.push(id);
            if (id === 2) controller.abort();
            signal.throwIfAborted();
        },
        () => {},
    );

    assert.deepEqual(attempted, [1, 2]);
    assert.deepEqual(result.saved, [1]);
    assert.deepEqual(result.failures, []);
    assert.equal(result.cancelled, true);
});

void test('Available Offline uses playable completed copies even when the remote track is absent', async () => {
    const { playableMediaCopies } =
        await import('../../resources/js/lib/mediaDownloads.ts');
    const copies = [
        { id: 1, available: true, mime_type: 'audio/wav' },
        { id: 2, available: false, mime_type: 'video/mp4' },
        { id: 3, available: true, mime_type: 'text/plain' },
        {
            id: 4,
            available: true,
            mime_type: 'application/octet-stream',
            metadata_protected: true,
        },
    ];

    assert.deepEqual(
        playableMediaCopies(copies).map((copy) => copy.id),
        [1, 4],
    );
});

void test('a queued Available Offline track keeps its local-only playback policy', async () => {
    const { mediaTrackMayUseRemote } =
        await import('../../resources/js/lib/mediaDownloads.ts');

    assert.equal(mediaTrackMayUseRemote({ localOnly: true }, false), false);
    assert.equal(mediaTrackMayUseRemote({}, true), false);
    assert.equal(mediaTrackMayUseRemote({}, false), true);
});
