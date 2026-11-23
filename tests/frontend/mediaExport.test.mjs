import assert from 'node:assert/strict';
import test from 'node:test';
import { exportMediaTracks } from '../../resources/js/lib/mediaDownloads.ts';

void test('exports playlist entries in order and reports failed files without stopping', async () => {
    const completed = [];
    const progress = [];
    const result = await exportMediaTracks(
        [3, 2, 3, 1],
        new AbortController().signal,
        async (id) => {
            if (id === 2) throw new Error('Vault is locked.');
            completed.push(id);
        },
        (update) => progress.push(update.id),
    );

    assert.deepEqual(progress, [3, 2, 1]);
    assert.deepEqual(completed, [3, 1]);
    assert.deepEqual(result, {
        saved: [3, 1],
        failures: [{ id: 2, message: 'Vault is locked.' }],
        cancelled: false,
    });
});
