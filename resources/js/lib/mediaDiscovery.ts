import type { MediaDetails } from './mediaMetadata';

const discoveryFields = ['artist', 'album', 'genre'] as const;

type DiscoveryField = (typeof discoveryFields)[number];

type DiscoveryTrack = {
    name: string;
    protected: boolean;
    details?: MediaDetails | null;
};

export type MediaDiscoveryGroup = {
    field: DiscoveryField;
    key: string;
};

type DiscoveryLabel = {
    key: string;
    name: string;
};

function searchKey(value?: string | null): string {
    return value?.trim().toLocaleLowerCase() ?? '';
}

export function mediaDiscoverySource<Track extends { discovered: boolean }>(
    tracks: Track[],
    folderOnly: boolean,
): Track[] {
    return folderOnly ? tracks.filter((track) => track.discovered) : tracks;
}

export function discoverMediaTracks<Track extends DiscoveryTrack>(
    tracks: readonly Track[],
    query: string,
    group: MediaDiscoveryGroup | null,
): Track[] {
    const term = searchKey(query);

    return tracks.filter((track) => {
        if (!term && !group) return true;
        if (track.protected) return false;

        const details = track.details;
        return (
            (!group || searchKey(details?.[group.field]) === group.key) &&
            (!term ||
                [
                    track.name,
                    details?.title,
                    details?.artist,
                    details?.album,
                ].some((value) => searchKey(value).includes(term)))
        );
    });
}

export function mediaDiscoveryGroups(
    tracks: readonly DiscoveryTrack[],
    query: string,
): Record<DiscoveryField, DiscoveryLabel[]> {
    const term = searchKey(query);
    const labels = {
        artist: new Map<string, string>(),
        album: new Map<string, string>(),
        genre: new Map<string, string>(),
    };

    for (const track of tracks) {
        if (track.protected) continue;
        for (const field of discoveryFields) {
            const name = track.details?.[field]?.trim();
            if (!name) continue;
            const key = searchKey(name);
            if (key.includes(term) && !labels[field].has(key)) {
                labels[field].set(key, name);
            }
        }
    }

    const sortedLabels = (field: DiscoveryField) =>
        [...labels[field]]
            .map(([key, name]) => ({ key, name }))
            .sort((left, right) => left.name.localeCompare(right.name));

    return {
        artist: sortedLabels('artist'),
        album: sortedLabels('album'),
        genre: sortedLabels('genre'),
    };
}
