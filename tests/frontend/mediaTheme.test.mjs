import assert from 'node:assert/strict';
import test from 'node:test';
import {
    readMediaTheme,
    saveMediaTheme,
} from '../../resources/js/lib/mediaTheme.ts';

void test('online and offline players can restore the same account preference', () => {
    const values = new Map([['appearance', 'dark']]);
    const storage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
    };

    assert.equal(readMediaTheme(storage, 7), 'default');
    saveMediaTheme(storage, 7, 'violet');

    assert.equal(readMediaTheme(storage, 7), 'violet');
    assert.equal(readMediaTheme(storage, 8), 'default');
    assert.equal(values.get('appearance'), 'dark');
    values.set('roxifile.media-theme.7', 'unknown');
    assert.equal(readMediaTheme(storage, 7), 'default');
});
