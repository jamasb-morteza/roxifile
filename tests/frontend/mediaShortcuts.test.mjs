import assert from 'node:assert/strict';
import test from 'node:test';
import {
    mediaShortcut,
    normalizeVolume,
} from '../../resources/js/lib/mediaShortcuts.ts';

test('shortcuts accept playback keys and ignore forms and modified keystrokes', () => {
    assert.equal(mediaShortcut({ key: 'ArrowRight' }, false), 'forward');
    assert.equal(mediaShortcut({ key: ' ' }, true), undefined);
    assert.equal(mediaShortcut({ key: 'j', ctrlKey: true }, false), undefined);
    assert.equal(mediaShortcut({ key: 'k' }, false), 'previous');
    assert.equal(
        mediaShortcut({ key: 'j', isComposing: true }, false),
        undefined,
    );
    assert.equal(mediaShortcut({ key: ' ', repeat: true }, false), undefined);
});

test('volume handles missing and malformed preferences and clamps adjustments', () => {
    assert.equal(normalizeVolume(null), 1);
    assert.equal(normalizeVolume('bad'), 1);
    assert.equal(normalizeVolume('0.25'), 0.25);
    assert.equal(normalizeVolume(-0.1), 0);
    assert.equal(normalizeVolume(1.1), 1);
});
