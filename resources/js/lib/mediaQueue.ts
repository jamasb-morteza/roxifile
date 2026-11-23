export type RepeatMode = 'off' | 'track' | 'playlist';

export function playbackQueue(
    ids: number[],
    selected: number,
    shuffle: boolean,
    random = Math.random,
): number[] {
    if (!shuffle) return [...ids];

    const remaining = ids.filter((id) => id !== selected);
    for (let index = remaining.length - 1; index > 0; index--) {
        const destination = Math.floor(random() * (index + 1));
        [remaining[index], remaining[destination]] = [
            remaining[destination],
            remaining[index],
        ];
    }

    return [selected, ...remaining];
}

export function nextTrack(
    queue: number[],
    current: number,
    repeat: RepeatMode,
): number | null {
    if (repeat === 'track') return current;
    return (
        queue[queue.indexOf(current) + 1] ??
        (repeat === 'playlist' ? (queue[0] ?? null) : null)
    );
}

export function enqueueTrack(
    queue: number[],
    current: number | undefined,
    id: number,
    playNext: boolean,
): number[] {
    return enqueueTracks(queue, current, [id], playNext);
}

export function enqueueTracks(
    queue: number[],
    current: number | undefined,
    ids: number[],
    playNext: boolean,
): number[] {
    const additions = new Set(ids);
    if (current !== undefined) additions.delete(current);
    if (!additions.size) return queue;

    const next = queue.filter((id) => !additions.has(id));
    const nextBoundary = current === undefined ? 0 : next.indexOf(current) + 1;
    next.splice(playNext ? nextBoundary : next.length, 0, ...additions);
    return next;
}

export function moveQueuedTrack(
    queue: number[],
    current: number | undefined,
    id: number,
    direction: -1 | 1,
): number[] {
    const boundary = current === undefined ? 0 : queue.indexOf(current) + 1;
    const index = queue.indexOf(id);
    const target = index + direction;
    if (index < boundary || target < boundary || target >= queue.length)
        return queue;
    const next = [...queue];
    [next[index], next[target]] = [next[target], next[index]];
    return next;
}

export function upcomingQueue(
    queue: number[],
    current: number | undefined,
    clear: boolean,
): number[] {
    const boundary = current === undefined ? 0 : queue.indexOf(current) + 1;
    return clear ? queue.slice(0, boundary) : queue.slice(boundary);
}

export function availableQueue(
    queue: number[],
    available: ReadonlySet<number>,
): number[] {
    return queue.filter((id) => available.has(id));
}
