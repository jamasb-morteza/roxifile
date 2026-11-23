import type { BrowserCopy } from './browserDownloads';

export type MediaDownloadProgress = {
    id: number;
    position: number;
    count: number;
    bytes: number;
    total: number;
};

type Download = (
    id: number,
    signal: AbortSignal,
    progress: (bytes: number, total: number) => void,
) => Promise<unknown>;

export async function downloadMediaBatch(
    ids: readonly number[],
    completed: ReadonlySet<number>,
    signal: AbortSignal,
    download: Download,
    progress: (update: MediaDownloadProgress) => void,
): Promise<{
    saved: number[];
    failures: { id: number; message: string }[];
    cancelled: boolean;
}> {
    const pending = [...new Set(ids)].filter((id) => !completed.has(id));
    const saved: number[] = [];
    const failures: { id: number; message: string }[] = [];

    for (const [index, id] of pending.entries()) {
        if (signal.aborted) break;
        const track = { id, position: index + 1, count: pending.length };
        progress({ ...track, bytes: 0, total: 0 });
        try {
            await download(id, signal, (bytes, total) =>
                progress({ ...track, bytes, total }),
            );
            saved.push(id);
        } catch (cause) {
            if (signal.aborted) break;
            failures.push({
                id,
                message:
                    cause instanceof DOMException &&
                    cause.name === 'QuotaExceededError'
                        ? 'Browser storage is full.'
                        : cause instanceof Error
                          ? cause.message
                          : 'Could not save this track.',
            });
        }
    }

    return { saved, failures, cancelled: signal.aborted };
}

export function playableMediaCopies(copies: BrowserCopy[]): BrowserCopy[] {
    return copies.filter(
        (copy) =>
            copy.available &&
            (copy.metadata_protected ||
                /^(audio|video)\//.test(copy.mime_type)),
    );
}

export function mediaTrackMayUseRemote(
    track: { localOnly?: boolean },
    offline: boolean,
): boolean {
    return !offline && !track.localOnly;
}

export function exportMediaTracks(
    ids: readonly number[],
    signal: AbortSignal,
    download: Download,
    progress: (update: MediaDownloadProgress) => void,
): ReturnType<typeof downloadMediaBatch> {
    return downloadMediaBatch(ids, new Set(), signal, download, progress);
}
