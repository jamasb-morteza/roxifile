import assert from 'node:assert/strict';
import test from 'node:test';

void test('crossfade selects the next playable audio without skipping queue entries', async () => {
    const { nextTrack } = await import('../../resources/js/lib/mediaQueue.ts');
    const { crossfadeCandidate } =
        await import('../../resources/js/lib/mediaCrossfade.ts');

    const telegram = { id: 2, mime_type: 'audio/mpeg' };
    const browser = { id: 3, mime_type: 'audio/wav', localOnly: true };
    const locked = { id: 4, mime_type: 'audio/wav', encrypted: true };
    const video = { id: 5, mime_type: 'video/webm' };
    const tracks = new Map(
        [telegram, browser, locked, video].map((track) => [track.id, track]),
    );
    const select = (
        queue,
        repeat = 'off',
        seconds = 4,
        copies = [],
        offline = false,
    ) =>
        crossfadeCandidate(
            nextTrack(queue, 1, repeat),
            1,
            'audio/mpeg',
            seconds,
            tracks,
            copies,
            offline,
        );

    assert.equal(select([1, 2, 3]), telegram);
    assert.equal(select([1, 3, 2]), undefined);
    assert.equal(
        select([1, 3, 2], 'off', 4, [{ id: 3, available: true }], true),
        browser,
    );
    assert.equal(select([1, 4, 2]), undefined);
    assert.equal(select([1, 5, 2]), undefined);
    assert.equal(select([1, 9, 2]), undefined);
    assert.equal(select([1, 2], 'track'), undefined);
    assert.equal(select([1, 2], 'off', 0), undefined);
    assert.equal(
        select(
            [1, 3],
            'off',
            4,
            [{ id: 3, available: true, encryption_header: 'sealed' }],
            true,
        ),
        undefined,
    );
});

void test('fade progress controls opposite gains and clamps to the configured window', async () => {
    const { crossfadeProgress } =
        await import('../../resources/js/lib/mediaCrossfade.ts');

    assert.equal(crossfadeProgress(6, 4), 0);
    assert.equal(crossfadeProgress(4, 4), 0);
    assert.equal(crossfadeProgress(3, 4), 0.25);
    assert.equal(crossfadeProgress(1, 4), 0.75);
    assert.equal(crossfadeProgress(0, 4), 1);
});
