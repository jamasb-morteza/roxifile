export type MediaTrackSort = 'default' | 'name-asc' | 'name-desc' | 'rating';

type MediaTrack = {
    id: number;
    name: string;
    protected: boolean;
    details?: { title?: string | null } | null;
    rating: number | null;
};

const trackNames = new Intl.Collator(undefined, {
    numeric: true,
    sensitivity: 'base',
});

export function mediaTrackName(
    track: Pick<MediaTrack, 'id' | 'name' | 'protected' | 'details'>,
): string {
    return track.protected
        ? `Protected file ${track.id}`
        : track.details?.title || track.name;
}

export function orderMediaTracks<Track extends MediaTrack>(
    tracks: Track[],
    sort: MediaTrackSort,
): Track[] {
    switch (sort) {
        case 'default':
            return tracks;
        case 'rating':
            return tracks.toSorted(
                (left, right) => (right.rating ?? 0) - (left.rating ?? 0),
            );
        case 'name-asc':
            return tracks.toSorted((left, right) =>
                trackNames.compare(mediaTrackName(left), mediaTrackName(right)),
            );
        case 'name-desc':
            return tracks.toSorted((left, right) =>
                trackNames.compare(mediaTrackName(right), mediaTrackName(left)),
            );
    }
}

export function selectedMediaIds(
    tracks: readonly Pick<MediaTrack, 'id'>[],
    selected: ReadonlySet<number>,
): number[] {
    return tracks
        .filter((track) => selected.has(track.id))
        .map((track) => track.id);
}

export function movePlaylistTrack(
    savedIds: readonly number[],
    visibleIds: readonly number[],
    trackId: number,
    direction: -1 | 1,
): number[] | undefined {
    const position = visibleIds.indexOf(trackId);
    const adjacentId = visibleIds[position + direction];
    if (position < 0 || adjacentId === undefined) return;

    const order = [...savedIds];
    const from = order.indexOf(trackId);
    const to = order.indexOf(adjacentId);
    if (from < 0 || to < 0) return;

    order[from] = adjacentId;
    order[to] = trackId;
    return order;
}
