import type { MediaPlaylist } from './browserDownloads';

export type PlaylistFolder = {
    id: number;
    parent_id: number | null;
    name: string;
};

type NavigationEntry = {
    kind: 'folder' | 'playlist';
    id: number;
    name: string;
    depth: number;
};

export function playlistNavigation(
    folders: PlaylistFolder[],
    playlists: MediaPlaylist[],
): NavigationEntry[] {
    const children = Map.groupBy(folders, (folder) => folder.parent_id);
    const folderIds = new Set(folders.map((folder) => folder.id));
    const members = Map.groupBy(playlists, (playlist) =>
        folderIds.has(playlist.folder_id ?? -1)
            ? (playlist.folder_id ?? null)
            : null,
    );
    const entries: NavigationEntry[] = [];

    function addChildren(parent: number | null, depth: number): void {
        for (const { id, name } of children.get(parent) ?? []) {
            entries.push({ kind: 'folder', id, name, depth });
            addChildren(id, depth + 1);
        }
        for (const { id, name } of members.get(parent) ?? []) {
            entries.push({ kind: 'playlist', id, name, depth });
        }
    }

    addChildren(null, 0);

    return entries;
}
