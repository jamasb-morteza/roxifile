import assert from 'node:assert/strict';
import test from 'node:test';
import { playbackQueue, nextTrack } from '../../resources/js/lib/mediaQueue.ts';

test('shuffle keeps the selected track first and plays each eligible track once', () => {
    assert.deepEqual(
        playbackQueue([1, 2, 3, 4], 2, true, () => 0),
        [2, 3, 4, 1],
    );
});

test('repeat track and repeat playlist are distinct from playing through once', () => {
    assert.equal(nextTrack([1, 2], 1, 'off'), 2);
    assert.equal(nextTrack([1, 2], 2, 'off'), null);
    assert.equal(nextTrack([1, 2], 2, 'playlist'), 1);
    assert.equal(nextTrack([1, 2], 2, 'track'), 2);
});

test('editing upcoming tracks preserves the current track and played history', async () => {
    const { enqueueTrack, moveQueuedTrack, upcomingQueue } =
        await import('../../resources/js/lib/mediaQueue.ts');
    assert.deepEqual(enqueueTrack([1, 2, 3, 4], 2, 4, true), [1, 2, 4, 3]);
    assert.deepEqual(enqueueTrack([1, 2, 3], 2, 4, false), [1, 2, 3, 4]);
    assert.deepEqual(enqueueTrack([1, 2, 3], 2, 2, true), [1, 2, 3]);
    assert.deepEqual(moveQueuedTrack([1, 2, 3, 4], 2, 3, -1), [1, 2, 3, 4]);
    assert.deepEqual(moveQueuedTrack([1, 2, 3, 4], 2, 4, -1), [1, 2, 4, 3]);
    assert.deepEqual(upcomingQueue([1, 2, 3, 4], 2, true), [1, 2]);
});

test('played tracks can be queued again and offline filtering preserves order', async () => {
    const { availableQueue, enqueueTrack } =
        await import('../../resources/js/lib/mediaQueue.ts');
    assert.deepEqual(enqueueTrack([1, 2, 3], 2, 1, true), [2, 1, 3]);
    assert.deepEqual(availableQueue([3, 2, 1, 4], new Set([1, 3])), [3, 1]);
});

test('bulk queue append moves selected tracks to the tail in their displayed order', async () => {
    const { enqueueTracks } =
        await import('../../resources/js/lib/mediaQueue.ts');
    assert.deepEqual(
        enqueueTracks([1, 2, 3, 4], 2, [4, 2, 1], false),
        [2, 3, 4, 1],
    );
});
