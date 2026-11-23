import test from 'node:test';
import assert from 'node:assert/strict';
import {
    availableBrowserCopyIds,
    fileBrowserCopies,
    localBrowserCopyIds,
} from '../../resources/js/lib/browserDownloads.ts';

void test('local browser copy ids list files whose saved copy is complete', () => {
    const ids = localBrowserCopyIds([
        { id: 1, available: true },
        { id: 2, available: false },
        { id: 3, available: true },
        { id: 4 },
    ]);
    assert.deepEqual([...ids], [1, 3]);
});

void test('available browser copy ids stay empty when browser storage is unavailable', async () => {
    assert.deepEqual(await availableBrowserCopyIds(7), new Set());
});

void test('file details only display the selected file browser snapshot', () => {
    const copies = [
        { account: 7, id: 1, name: 'one.txt', available: true },
        { account: 7, id: 2, name: 'two.txt', available: false },
    ];
    assert.deepEqual(fileBrowserCopies(copies, 2), [copies[1]]);
    assert.deepEqual(fileBrowserCopies(copies, 3), []);
    assert.deepEqual(fileBrowserCopies(copies), copies);
});
