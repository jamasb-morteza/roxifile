export type PendingPlayback = { fileId: number; eventId: string };

function storageKey(account: number): string {
    return `roxifile.media-playback.${account}`;
}

export function queueMediaPlayback(
    storage: Pick<Storage, 'getItem' | 'setItem'>,
    account: number,
    playback: PendingPlayback,
): void {
    const pending = readPending(storage, account);
    if (pending.some((item) => item.eventId === playback.eventId)) return;
    pending.push(playback);
    storage.setItem(storageKey(account), JSON.stringify(pending));
}

export function pendingMediaPlayback(
    storage: Pick<Storage, 'getItem'>,
    account: number,
): PendingPlayback[] {
    return readPending(storage, account);
}

function readPending(
    storage: Pick<Storage, 'getItem'>,
    account: number,
): PendingPlayback[] {
    try {
        const pending = JSON.parse(
            storage.getItem(storageKey(account)) ?? '[]',
        );
        return Array.isArray(pending)
            ? pending.filter(
                  (item): item is PendingPlayback =>
                      Number.isSafeInteger(item?.fileId) &&
                      typeof item?.eventId === 'string',
              )
            : [];
    } catch {
        return [];
    }
}

export async function syncMediaPlayback(
    storage: Pick<Storage, 'getItem' | 'setItem'>,
    account: number,
    endpoint: (fileId: number) => string,
): Promise<number[]> {
    if (
        Number(storage.getItem('roxifile.browser-account')) !== account ||
        typeof document === 'undefined'
    )
        return [];
    const token = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length) ?? '',
    );
    const synced: number[] = [];
    for (const item of readPending(storage, account)) {
        if (Number(storage.getItem('roxifile.browser-account')) !== account)
            break;
        try {
            const response = await fetch(endpoint(item.fileId), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': token,
                },
                body: JSON.stringify({ event_id: item.eventId }),
                keepalive: true,
            });
            if (!response.ok) continue;
            const remaining = readPending(storage, account).filter(
                (pending) => pending.eventId !== item.eventId,
            );
            storage.setItem(storageKey(account), JSON.stringify(remaining));
            synced.push(item.fileId);
        } catch {
            break;
        }
    }
    return synced;
}
