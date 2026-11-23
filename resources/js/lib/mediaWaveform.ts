export function waveformPeaks(
    samples: Float32Array,
    columns: number,
): number[] {
    const count = Math.min(samples.length, columns);
    return Array.from({ length: count }, (_, column) => {
        const start = Math.floor((column * samples.length) / count);
        const end = Math.floor(((column + 1) * samples.length) / count);
        let peak = 0;
        for (let sample = start; sample < end; sample++) {
            peak = Math.max(peak, Math.abs(samples[sample]));
        }
        return peak;
    });
}
