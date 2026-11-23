import assert from 'node:assert/strict';
import test from 'node:test';
import { waveformPeaks } from '../../resources/js/lib/mediaWaveform.ts';

test('waveform groups actual time-domain samples and preserves silence', () => {
    assert.deepEqual(
        waveformPeaks(Float32Array.of(0, 0, -0.75, 0.25, 0), 3),
        [0, 0.75, 0.25],
    );
    assert.deepEqual(waveformPeaks(Float32Array.of(0), 4), [0]);
});
