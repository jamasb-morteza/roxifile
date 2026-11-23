import type { BrowserCopy } from './browserDownloads';

export function crossfadeCandidate<
    T extends {
        id: number;
        mime_type: string;
        encrypted?: boolean;
        localOnly?: boolean;
    },
>(
    id: number | null,
    currentId: number | undefined,
    mime: string,
    seconds: number,
    tracks: ReadonlyMap<number, T>,
    copies: readonly BrowserCopy[],
    offline: boolean,
): T | undefined {
    if (
        !seconds ||
        id === null ||
        id === currentId ||
        !mime.startsWith('audio/')
    )
        return;
    const track = tracks.get(id);
    if (!track || track.encrypted || !track.mime_type.startsWith('audio/'))
        return;
    const copy = copies.find((item) => item.id === id && item.available);
    if (copy?.encryption_header || (!copy && (offline || track.localOnly)))
        return;
    return track;
}

export function crossfadeProgress(remaining: number, seconds: number): number {
    return Math.max(0, Math.min(1, 1 - remaining / seconds));
}
