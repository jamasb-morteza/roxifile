import assert from 'node:assert/strict';
import test from 'node:test';

void test('Telegram streams and browser downloads share a signal path with adjustable bands and bypass', async () => {
    const { createMediaAudioGraph, equalizerPresets } =
        await import('../../resources/js/lib/mediaEqualizer.ts');

    for (const src of [
        'https://roxifile.test/files/3/download?operation_id=4&inline=1',
        'blob:https://roxifile.test/browser-copy',
    ]) {
        const context = audioContext();
        const element = { src };
        const graph = createMediaAudioGraph(
            context,
            element,
            'https://roxifile.test',
        );
        assert.ok(graph);
        assert.equal(context.source.element, element);

        graph.setEqualizer(equalizerPresets['Bass boost'], false);
        assert.deepEqual(context.audibleGains(), [6, 4, 0, 0, 0]);

        const custom = [6, 4, -3, 0, 0];
        graph.setEqualizer(custom, false);
        assert.deepEqual(context.audibleGains(), custom);
        graph.setEqualizer(custom, true);
        assert.deepEqual(context.audibleGains(), [0, 0, 0, 0, 0]);
        graph.setEqualizer(custom, false);
        assert.deepEqual(context.audibleGains(), custom);

        graph.disconnect();
        assert.equal(context.source.output, undefined);
    }
});

void test('unavailable audio processing leaves native playback connected', async () => {
    const { createMediaAudioGraph } =
        await import('../../resources/js/lib/mediaEqualizer.ts');
    const origin = 'https://roxifile.test';
    const element = { src: origin + '/files/3/download' };
    const suspended = audioContext();
    suspended.state = 'suspended';
    assert.equal(createMediaAudioGraph(suspended, element, origin), undefined);
    assert.equal(suspended.source, undefined);

    const remote = audioContext();
    assert.equal(
        createMediaAudioGraph(
            remote,
            { src: 'https://remote.test/audio.wav' },
            origin,
        ),
        undefined,
    );
    assert.equal(remote.source, undefined);

    const unsupported = audioContext();
    unsupported.createBiquadFilter = () => {
        throw new Error('Audio filters unavailable');
    };
    assert.equal(
        createMediaAudioGraph(unsupported, element, origin),
        undefined,
    );
    assert.equal(unsupported.source, undefined);
});

function audioContext() {
    const node = () => ({
        connect(destination) {
            this.output = destination;
            return destination;
        },
        disconnect() {
            this.output = undefined;
        },
    });

    return {
        state: 'running',
        sampleRate: 48000,
        destination: node(),
        createAnalyser: node,
        createBiquadFilter: () => ({
            ...node(),
            frequency: { value: 0 },
            gain: { value: 0 },
        }),
        createMediaElementSource(element) {
            this.source = { ...node(), element };
            return this.source;
        },
        audibleGains() {
            const gains = [];
            let output = this.source.output;
            while (output !== this.destination) {
                assert.ok(output, 'The signal must reach the destination');
                if (output.gain) gains.push(output.gain.value);
                output = output.output;
            }
            return gains;
        },
    };
}
