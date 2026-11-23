export const equalizerFrequencies = [60, 250, 1000, 4000, 12000] as const;

export const equalizerPresets = {
    Flat: [0, 0, 0, 0, 0],
    'Bass boost': [6, 4, 0, 0, 0],
    'Vocal boost': [-3, -1, 4, 3, 0],
    'Treble boost': [0, 0, 0, 3, 6],
} as const;

export function canProcessMedia(
    element: HTMLMediaElement,
    origin: string,
): boolean {
    const url = element.currentSrc || element.src;
    if (!url) return false;
    const source = new URL(url, origin);
    return (
        source.origin === origin &&
        ['blob:', 'https:', 'http:'].includes(source.protocol)
    );
}

export function createMediaAudioGraph(
    context: AudioContext,
    element: HTMLMediaElement,
    origin: string,
) {
    if (context.state !== 'running' || !canProcessMedia(element, origin))
        return;

    try {
        const analyser = context.createAnalyser();
        const bands = equalizerFrequencies.map((frequency, index) => {
            const band = context.createBiquadFilter();
            band.type =
                index === 0
                    ? 'lowshelf'
                    : index === 4
                      ? 'highshelf'
                      : 'peaking';
            band.frequency.value = Math.min(frequency, context.sampleRate / 2);
            return band;
        });
        bands.forEach((band, index) =>
            band.connect(bands[index + 1] ?? analyser),
        );
        analyser.connect(context.destination);

        // Attaching a media source transfers playback to this graph, so build the output path first.
        const source = context.createMediaElementSource(element);
        source.connect(bands[0]);

        return {
            analyser,
            setEqualizer(gains: readonly number[], bypass: boolean): void {
                bands.forEach((band, index) => {
                    band.gain.value = bypass ? 0 : gains[index];
                });
            },
            disconnect(): void {
                source.disconnect();
                bands.forEach((band) => band.disconnect());
                analyser.disconnect();
            },
        };
    } catch {
        return undefined;
    }
}
