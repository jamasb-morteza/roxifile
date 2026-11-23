<script setup lang="ts">
import { useLocalization } from '@/composables/useLocalization';
import { Head, router, useHttp, usePage } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    CloudDownload,
    Folder,
    FolderPlus,
    Info,
    Heart,
    ListMusic,
    Music2,
    Play,
    Plus,
    Search,
    Tag,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import MediaExportDialog from '@/components/MediaExportDialog.vue';
import MediaCover from '@/components/MediaCover.vue';
import MediaDiscovery from '@/components/MediaDiscovery.vue';
import MediaTrackDetails from '@/components/MediaTrackDetails.vue';
import MediaSmartPlaylistForm from '@/components/MediaSmartPlaylistForm.vue';
import MediaThemePicker from '@/components/MediaThemePicker.vue';
import type { MediaTheme } from '@/lib/mediaTheme';
import MediaPlayback from '@/components/MediaPlayback.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    browserOriginal,
    downloadToBrowser,
    preparedDownloadUrl,
} from '@/lib/browserDownloadTransfer';
import {
    browserAccount,
    browserLogoutEpoch,
    cacheMediaPlaylists,
    installOfflineShell,
    listBrowserCopies,
    localBrowserCopyIds,
    offlineShell,
    onBrowserCopiesChanged,
    type BrowserCopy,
    type MediaPlaylist,
    type MediaSmartRules,
} from '@/lib/browserDownloads';
import {
    downloadMediaBatch,
    playableMediaCopies,
    type MediaDownloadProgress,
} from '@/lib/mediaDownloads';
import { stageDownload } from '@/lib/contentsEncryption';
import {
    discoverMediaTracks,
    mediaDiscoverySource,
    type MediaDiscoveryGroup,
} from '@/lib/mediaDiscovery';
import type { MediaDetails } from '@/lib/mediaMetadata';
import {
    queueMediaPlayback,
    syncMediaPlayback,
} from '@/lib/mediaPlaybackHistory';
import {
    mediaTrackName,
    movePlaylistTrack,
    orderMediaTracks,
    selectedMediaIds,
    type MediaTrackSort,
} from '@/lib/mediaTrackList';
import {
    playlistNavigation,
    type PlaylistFolder,
} from '@/lib/playlistNavigation';
import { index as mediaIndex, library } from '@/routes/media';
import {
    destroy as destroyFolder,
    store as storeFolder,
    update as updateFolder,
} from '@/routes/media/folders';
import {
    destroy as destroyPlaylist,
    store as storePlaylist,
    update as updatePlaylist,
} from '@/routes/media/playlists';
import { playback, update as updateTrack } from '@/routes/media/tracks';
import { index as filesIndex, streamDownload } from '@/routes/files';
import { attach, detach } from '@/routes/files/tags';

type Track = {
    id: number;
    name: string;
    mime_type: string;
    encrypted: boolean;
    protected: boolean;
    streamable?: boolean;
    discovered: boolean;
    favorite: boolean;
    rating: number | null;
    tags: { id: number; name: string }[];
    details?: MediaDetails | null;
};
const props = defineProps<{
    directoryId: number | null;
    directories: { id: number; path: string }[];
    artwork: { id: number; name: string }[];
    tracks: Track[];
    playlists: MediaPlaylist[];
    folders: PlaylistFolder[];
    history: {
        recent: number[];
        most: number[];
        counts: Record<number, number>;
    };
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Media Player', href: mediaIndex() }] },
});
const account = Number(usePage().props.auth.user.id);
const logoutEpoch = browserLogoutEpoch(account);
const mediaTheme = ref<MediaTheme>('default');
const player = ref<InstanceType<typeof MediaPlayback> | null>(null);
const copies = ref<BrowserCopy[]>([]);
const offline = ref(false);
const connected = ref(true);
const offlineReady = ref(false);
const selected = ref<number | 'favorites' | 'offline' | 'discover' | null>(
    null,
);
const name = ref('');
const trackDetailsOpen = ref(false);
const trackForDetails = ref<Track>();
const folderDialogOpen = ref(false);
const folderBeingEdited = ref<number | null>(null);
const folderName = ref('');
const folderParent = ref<number | null>(null);
const confirmFolderDeletion = ref(false);
const deleteConfirmationOpen = ref(false);
const tagEditor = ref<number | null>(null);
const tagName = ref('');
const exportIds = ref<number[]>([]);
const exportOpen = ref(false);
const exportNames = computed(
    () =>
        new Map(props.tracks.map((track) => [track.id, mediaTrackName(track)])),
);
function openExport(ids: readonly number[]): void {
    exportIds.value = [...ids];
    exportOpen.value = true;
}

const downloadRequest = ref<AbortController>();
const cancellingDownload = ref(false);
const downloadProgress = ref<MediaDownloadProgress>();
const downloadResult = ref<Awaited<ReturnType<typeof downloadMediaBatch>>>();
const downloadNames = ref(new Map<number, string>());
const requests = new Set<AbortController>();
let unsubscribe: (() => void) | undefined;
const activePlaylist = computed(() =>
    props.playlists.find((playlist) => playlist.id === selected.value),
);
const manualPlaylists = computed(() =>
    props.playlists.filter((playlist) => !playlist.smart_rules),
);
const smartPlaylistOpen = ref(false);
const smartPlaylistBeingEdited = ref<MediaPlaylist>();

function openSmartPlaylist(playlist?: MediaPlaylist): void {
    smartPlaylistBeingEdited.value = playlist;
    smartPlaylistOpen.value = true;
}

function saveSmartPlaylist(name: string, rules: MediaSmartRules): void {
    const playlist = smartPlaylistBeingEdited.value;
    submit(
        playlist ? 'patch' : 'post',
        playlist ? updatePlaylist.url(playlist.id) : storePlaylist.url(),
        {
            name,
            smart_rules: rules,
        },
        () => (smartPlaylistOpen.value = false),
    );
}

const navigation = computed(() =>
    playlistNavigation(props.folders, props.playlists),
);
const folderOptions = computed(() =>
    navigation.value.filter((entry) => entry.kind === 'folder'),
);
const offlineMode = computed(() => offline.value || !connected.value);
const copyIds = computed(() => localBrowserCopyIds(copies.value));
const tracksById = computed(
    () => new Map(props.tracks.map((track) => [track.id, track])),
);
const historySections = computed(() =>
    [
        {
            title: 'Recently played',
            ids: props.history.recent,
            showCount: false,
        },
        { title: 'Most played', ids: props.history.most, showCount: true },
    ].map(({ title, ids, showCount }) => ({
        title,
        showCount,
        tracks: ids.flatMap((id) => tracksById.value.get(id) ?? []),
    })),
);
const offlineTracks = computed(() => {
    const completed = new Map<number, Track>(
        playableMediaCopies(copies.value).map((copy) => [
            copy.id,
            tracksById.value.get(copy.id) ?? {
                id: copy.id,
                name: copy.name,
                mime_type: copy.mime_type,
                encrypted: copy.encryption_header !== null,
                protected: copy.metadata_protected,
                streamable: false,
                favorite: false,
                rating: null,
                discovered: false,
                tags: copy.tags,
            },
        ]),
    );
    for (const track of props.tracks) {
        if (copyIds.value.has(track.id)) completed.set(track.id, track);
    }
    return [...completed.values()].map((track) => ({
        ...track,
        localOnly: true,
    }));
});
const activeTag = ref<number | null>(null);
const availableTags = computed(() =>
    Array.from(
        new Map(
            props.tracks
                .flatMap((track) => track.tags)
                .map((tag) => [tag.id, tag]),
        ).values(),
    ),
);

const search = ref('');
const discoveryQuery = ref('');
const discoveryGroup = ref<MediaDiscoveryGroup | null>(null);
const discoveryFolderOnly = ref(false);
const discoverySource = computed(() =>
    mediaDiscoverySource(
        offlineMode.value ? offlineTracks.value : props.tracks,
        discoveryFolderOnly.value && props.directoryId !== null,
    ),
);
const sort = ref<MediaTrackSort>('default');
const selectedTracks = ref(new Set<number>());
const destination = ref<number | null>(null);
const matchingTracks = computed(() => {
    if (selected.value === 'discover') {
        return discoverMediaTracks(
            discoverySource.value,
            discoveryQuery.value,
            discoveryGroup.value,
        ).filter(
            (track) =>
                activeTag.value === null ||
                track.tags.some((tag) => tag.id === activeTag.value),
        );
    }
    const query = search.value.trim().toLocaleLowerCase();
    const source =
        selected.value === 'offline' ? offlineTracks.value : props.tracks;
    const eligible = source.filter(
        (track) =>
            (!offlineMode.value || copyIds.value.has(track.id)) &&
            (!query ||
                mediaTrackName(track).toLocaleLowerCase().includes(query)) &&
            (activeTag.value === null ||
                track.tags.some((tag) => tag.id === activeTag.value)),
    );
    if (selected.value === 'offline') return eligible;
    if (activePlaylist.value) {
        const tracks = new Map(eligible.map((track) => [track.id, track]));
        return activePlaylist.value.file_ids.flatMap(
            (id) => tracks.get(id) ?? [],
        );
    }
    return eligible.filter((track) =>
        selected.value === 'favorites' ? track.favorite : track.discovered,
    );
});

const visibleTracks = computed(() =>
    orderMediaTracks(matchingTracks.value, sort.value),
);
const canReorder = computed(
    () =>
        activePlaylist.value &&
        !activePlaylist.value.smart_rules &&
        !offlineMode.value &&
        sort.value === 'default',
);
const selectedIds = computed(() =>
    selectedMediaIds(visibleTracks.value, selectedTracks.value),
);
const selectedOriginalIds = computed(() =>
    selectedIds.value.filter((id) => tracksById.value.has(id)),
);
const allSelected = computed(
    () => selectedIds.value.length === visibleTracks.value.length,
);

function toggleAllTracks(): void {
    selectedTracks.value = allSelected.value
        ? new Set()
        : new Set(visibleTracks.value.map((track) => track.id));
}

function queueSelection(): void {
    player.value?.enqueueSelection(selectedIds.value);
}

function saveSelection(): void {
    if (destination.value === null || !selectedOriginalIds.value.length) return;
    submit(
        'patch',
        updatePlaylist.url(destination.value),
        {
            add_file_ids: selectedOriginalIds.value,
        },
        () => {
            toast.success('Tracks added to playlist');
        },
    );
}

type FormData = Record<
    string,
    string | number | boolean | null | string[] | number[] | MediaSmartRules
>;
const form = useHttp<FormData>({});
const reloading = ref(false);
const mutationPending = computed(() => form.processing || reloading.value);
function submit(
    method: 'post' | 'patch' | 'delete',
    url: string,
    data: FormData,
    done?: () => void,
): void {
    if (mutationPending.value) return;
    void form
        .transform(() => data)
        .submit(method, url, {
            onSuccess: () => {
                done?.();
                reloading.value = true;
                router.reload({
                    only: [
                        'directoryId',
                        'tracks',
                        'playlists',
                        'folders',
                        'artwork',
                    ],
                    onFinish: () => {
                        reloading.value = false;
                    },
                });
            },
            onError: (errors) =>
                toast.error(Object.values(errors).flat().join(' ')),
            onHttpException: () => {
                toast.error('Could not save your change.');
                return false;
            },
            onNetworkError: () => {
                toast.error('Could not connect to save your change.');
            },
        })
        .catch(() => {});
}

async function syncPlaybackHistory(): Promise<void> {
    if (offlineMode.value || !connected.value) return;
    try {
        const synced = await syncMediaPlayback(
            window.localStorage,
            account,
            (fileId) => playback.url(fileId),
        );
        if (synced.length)
            router.reload({ only: ['history', 'tracks', 'playlists'] });
    } catch {
        // Playback continues if browser storage is unavailable.
    }
}

function recordPlayback(trackId: number): void {
    if (!tracksById.value.has(trackId)) return;
    try {
        queueMediaPlayback(window.localStorage, account, {
            fileId: trackId,
            eventId: crypto.randomUUID(),
        });
    } catch {
        return;
    }
    void syncPlaybackHistory();
}

function changeDirectory(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    if (selected.value === 'discover') {
        discoveryFolderOnly.value = Boolean(value);
        discoveryGroup.value = null;
    }
    submit('patch', library.url(), {
        directory_id: value ? Number(value) : null,
    });
}

function savePlaylist(): void {
    const title = name.value.trim();
    if (!title) return;
    const playlist = activePlaylist.value;
    if (playlist)
        submit('patch', updatePlaylist.url(playlist.id), { name: title });
    else
        submit('post', storePlaylist.url(), { name: title }, () => {
            name.value = '';
        });
}

function reorderPlaylistTrack(trackId: number, direction: -1 | 1): void {
    const playlist = activePlaylist.value;
    if (!playlist || !canReorder.value) return;

    const order = movePlaylistTrack(
        playlist.file_ids,
        visibleTracks.value.map((track) => track.id),
        trackId,
        direction,
    );
    if (order) {
        submit(
            'patch',
            updatePlaylist.url(playlist.id),
            {
                ordered_file_ids: order,
            },
            () => {
                toast.success('Playlist order saved');
            },
        );
    }
}

function deletePlaylist(): void {
    const playlist = activePlaylist.value;
    if (!playlist || offlineMode.value) return;

    submit('delete', destroyPlaylist.url(playlist.id), {}, () => {
        deleteConfirmationOpen.value = false;
        if (selected.value === playlist.id) selected.value = null;
        toast.success('Playlist deleted');
    });
}

function openFolder(folder?: PlaylistFolder): void {
    folderBeingEdited.value = folder?.id ?? null;
    folderName.value = folder?.name ?? '';
    folderParent.value = folder?.parent_id ?? null;
    confirmFolderDeletion.value = false;
    folderDialogOpen.value = true;
}

function editFolder(id: number): void {
    const folder = props.folders.find((candidate) => candidate.id === id);
    if (folder) openFolder(folder);
}

function saveFolder(): void {
    const name = folderName.value.trim();
    if (!name) return;

    const folderId = folderBeingEdited.value;
    const data = { name, parent_id: folderParent.value };
    const close = () => {
        folderDialogOpen.value = false;
    };
    if (folderId === null) {
        submit('post', storeFolder.url(), data, close);
    } else {
        submit('patch', updateFolder.url(folderId), data, close);
    }
}

function deleteFolder(): void {
    if (folderBeingEdited.value === null) return;

    submit('delete', destroyFolder.url(folderBeingEdited.value), {}, () => {
        folderDialogOpen.value = false;
        toast.success('Folder deleted');
    });
}

function openTrackDetails(track: Track): void {
    router.reload({ only: ['artwork'] });
    trackForDetails.value = track;
    trackDetailsOpen.value = true;
}

function onTrackDetailsSaved(): void {
    trackDetailsOpen.value = false;
    router.reload({ only: ['tracks', 'artwork', 'playlists'] });
}

function setPlaylistCover(playlist: MediaPlaylist, event: Event): void {
    const field = event.target as HTMLSelectElement;
    const id = field.value ? Number(field.value) : null;
    field.value = playlist.cover_file_id?.toString() ?? '';
    submit('patch', updatePlaylist.url(playlist.id), { cover_file_id: id });
}

function movePlaylist(playlist: MediaPlaylist, event: Event): void {
    const field = event.target as HTMLSelectElement;
    const folderId = field.value ? Number(field.value) : null;
    field.value = playlist.folder_id?.toString() ?? '';
    submit('patch', updatePlaylist.url(playlist.id), { folder_id: folderId });
}

function addToPlaylist(track: Track, event: Event): void {
    const select = event.target as HTMLSelectElement;
    if (select.value)
        submit('patch', updatePlaylist.url(Number(select.value)), {
            add_file_id: track.id,
        });
    select.value = '';
}

async function refreshCopies(): Promise<void> {
    if (
        browserAccount() !== account ||
        browserLogoutEpoch(account) !== logoutEpoch
    ) {
        exportOpen.value = false;
        trackDetailsOpen.value = false;
        trackForDetails.value = undefined;
        player.value?.stop();
        for (const request of requests) request.abort();
        copies.value = [];
        return;
    }
    copies.value = await listBrowserCopies(account).catch(() => []);
}

async function remotePlaybackUrl(id: number, signal: AbortSignal): Promise<string> {
    return tracksById.value.get(id)?.streamable
        ? streamDownload({ file: id }).url
        : preparedDownloadUrl(id, signal, true);
}

async function remoteEncrypted(id: number, signal: AbortSignal) {
    const snapshot = await browserOriginal(id, signal);
    if (!snapshot?.encryption_header)
        throw new Error('Encrypted file details are unavailable.');
    return {
        copy: {
            ...snapshot,
            account,
            path: '',
            saved_at: '',
            original_missing: false,
        },
        load: async () => {
            const url = await preparedDownloadUrl(id, signal);
            return stageDownload(
                await fetch(url, { signal }),
                snapshot.size,
                signal,
            );
        },
    };
}

async function saveOffline(ids: readonly number[]): Promise<void> {
    if (downloadRequest.value || offlineMode.value) return;
    const request = new AbortController();
    requests.add(request);
    downloadRequest.value = request;
    cancellingDownload.value = false;
    downloadProgress.value = undefined;
    downloadResult.value = undefined;
    downloadNames.value = new Map(
        props.tracks.map((track) => [track.id, mediaTrackName(track)]),
    );

    try {
        const result = await downloadMediaBatch(
            ids,
            copyIds.value,
            request.signal,
            (id, signal, progress) =>
                downloadToBrowser(account, id, signal, progress),
            (update) => (downloadProgress.value = update),
        );
        downloadResult.value = result;
        if (result.saved.length) offlineReady.value = true;
        await refreshCopies();
    } finally {
        requests.delete(request);
        downloadRequest.value = undefined;
        downloadProgress.value = undefined;
    }
}

function cancelDownloads(): void {
    cancellingDownload.value = true;
    downloadRequest.value?.abort();
}

function downloadName(id: number): string {
    return downloadNames.value.get(id) ?? `Track ${id}`;
}

function updateConnection(): void {
    connected.value = navigator.onLine;
    if (connected.value) void syncPlaybackHistory();
}
function cachePlaylists(): void {
    try {
        cacheMediaPlaylists(account, props.playlists);
    } catch {
        toast.error('Playlists could not be cached for offline use.');
    }
}
watch(() => props.playlists, cachePlaylists);
watch(offlineMode, (offline) => {
    if (!offline) void syncPlaybackHistory();
});
watch(selected, () => {
    deleteConfirmationOpen.value = false;
    name.value = activePlaylist.value?.name ?? '';
    sort.value = 'default';
    selectedTracks.value.clear();
});
watch(matchingTracks, (tracks) => {
    selectedTracks.value = new Set(
        selectedMediaIds(tracks, selectedTracks.value),
    );
});
watch(
    () => props.playlists,
    (playlists) => {
        if (!playlists.some((playlist) => playlist.id === destination.value)) {
            destination.value = null;
        }
    },
);
onMounted(() => {
    updateConnection();
    window.addEventListener('online', updateConnection);
    window.addEventListener('offline', updateConnection);
    unsubscribe = onBrowserCopiesChanged(() => void refreshCopies());
    void refreshCopies();
    cachePlaylists();
    void installOfflineShell()
        .then(() => {
            offlineReady.value = true;
        })
        .catch(() => {});
});
onBeforeUnmount(() => {
    unsubscribe?.();
    window.removeEventListener('online', updateConnection);
    window.removeEventListener('offline', updateConnection);
    for (const request of requests) request.abort();
});

const { t } = useLocalization();
</script>

<template>
    <div
        :data-media-theme="mediaTheme"
        class="bg-background text-foreground grid min-h-[calc(100dvh-7rem)] w-full rounded-xl border lg:grid-cols-[14rem_minmax(0,1fr)]"
    >
        <Head title="Media Player" />
        <aside class="bg-muted/60 border-b p-4 lg:border-r lg:border-b-0">
            <h1 class="mb-5 flex items-center gap-2 text-lg font-semibold">
                <Music2
                    class="size-5 text-[var(--media-theme-highlight,var(--color-rose-600))]"
                />
                {{ t('Media Player') }}
            </h1>
            <MediaThemePicker
                v-model="mediaTheme"
                :account="account"
                class="mb-4"
            />
            <nav
                aria-label="Media library"
                class="flex flex-wrap gap-1 lg:flex-col"
            >
                <Button
                    variant="ghost"
                    class="justify-start"
                    :aria-current="selected === null ? 'page' : undefined"
                    :class="{
                        'bg-accent text-[var(--media-theme-highlight,var(--color-rose-600))]':
                            selected === null,
                    }"
                    @click="selected = null"
                    ><Music2 class="size-4" /> {{ t('All media') }}</Button
                >
                <Button
                    variant="ghost"
                    class="justify-start"
                    :aria-current="
                        selected === 'favorites' ? 'page' : undefined
                    "
                    :class="{
                        'bg-accent text-[var(--media-theme-highlight,var(--color-rose-600))]':
                            selected === 'favorites',
                    }"
                    @click="selected = 'favorites'"
                    ><Heart class="size-4" /> {{ t('Favorites') }}</Button
                >
                <Button
                    variant="ghost"
                    class="justify-start"
                    :aria-current="selected === 'offline' ? 'page' : undefined"
                    :class="{
                        'bg-accent text-[var(--media-theme-highlight,var(--color-rose-600))]':
                            selected === 'offline',
                    }"
                    @click="selected = 'offline'"
                >
                    <CloudDownload class="size-4" /> {{ t('Available Offline') }}
                </Button>
                <Button
                    variant="ghost"
                    class="justify-start"
                    :aria-current="selected === 'discover' ? 'page' : undefined"
                    :class="{
                        'bg-accent text-rose-500': selected === 'discover',
                    }"
                    @click="selected = 'discover'"
                >
                    <Search class="size-4" /> {{ t('Discover') }}
                </Button>
            </nav>
            <h2
                class="text-muted-foreground mt-5 mb-2 text-xs font-semibold tracking-wider uppercase"
            >
                Playlists
            </h2>
            <nav
                aria-label="Playlists"
                class="flex flex-wrap gap-1 lg:flex-col"
            >
                <template
                    v-for="entry in navigation"
                    :key="entry.kind + entry.id"
                >
                    <Button
                        v-if="entry.kind === 'folder'"
                        variant="ghost"
                        class="justify-start overflow-hidden"
                        :style="{
                            paddingInlineStart: `calc(0.75rem + ${Math.min(entry.depth, 6)} * 0.75rem)`,
                        }"
                        :disabled="offlineMode || mutationPending"
                        :aria-label="'Edit folder ' + entry.name"
                        @click="editFolder(entry.id)"
                    >
                        <Folder class="size-4 shrink-0" /><span
                            class="truncate"
                            >{{ entry.name }}</span
                        >
                    </Button>
                    <Button
                        v-else
                        variant="ghost"
                        class="justify-start overflow-hidden"
                        :style="{
                            paddingInlineStart: `calc(0.75rem + ${Math.min(entry.depth, 6)} * 0.75rem)`,
                        }"
                        :aria-current="
                            selected === entry.id ? 'page' : undefined
                        "
                        :class="{
                            'bg-accent text-[var(--media-theme-highlight,var(--color-rose-600))]':
                                selected === entry.id,
                        }"
                        @click="selected = entry.id"
                    >
                        <ListMusic class="size-4 shrink-0" /><span
                            class="truncate"
                            >{{ entry.name }}</span
                        >
                    </Button>
                </template>
            </nav>
            <Dialog v-if="!offlineMode" v-model:open="folderDialogOpen">
                <DialogTrigger as-child>
                    <Button
                        variant="ghost"
                        class="mt-2 justify-start"
                        :disabled="mutationPending"
                        @click="openFolder()"
                    >
                        <FolderPlus class="size-4" /> {{ t('New folder') }}
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <template v-if="confirmFolderDeletion">
                        <DialogTitle>Delete folder?</DialogTitle>
                        <DialogDescription>
                            Its playlists and subfolders will move to the top
                            level.
                        </DialogDescription>
                        <DialogFooter>
                            <Button
                                variant="secondary"
                                :disabled="mutationPending"
                                @click="confirmFolderDeletion = false"
                                >Back</Button
                            >
                            <Button
                                variant="destructive"
                                :disabled="mutationPending"
                                @click="deleteFolder"
                                >Delete folder</Button
                            >
                        </DialogFooter>
                    </template>
                    <template v-else>
                        <DialogTitle>{{
                            folderBeingEdited === null
                                ? 'New playlist folder'
                                : 'Edit playlist folder'
                        }}</DialogTitle>
                        <DialogDescription
                            >Folders organize the playlists in your media
                            library.</DialogDescription
                        >
                        <form class="space-y-4" @submit.prevent="saveFolder">
                            <label class="block space-y-1 text-sm">
                                <span>Folder name</span>
                                <Input
                                    v-model="folderName"
                                    maxlength="100"
                                    required
                                />
                            </label>
                            <label class="block space-y-1 text-sm">
                                <span>Parent folder</span>
                                <select
                                    v-model="folderParent"
                                    class="bg-background block w-full rounded-md border p-2"
                                >
                                    <option :value="null">Top level</option>
                                    <option
                                        v-for="folder in folderOptions"
                                        :key="folder.id"
                                        :value="folder.id"
                                        :disabled="
                                            folder.id === folderBeingEdited
                                        "
                                    >
                                        {{
                                            '　'.repeat(folder.depth) +
                                            folder.name
                                        }}
                                    </option>
                                </select>
                            </label>
                            <DialogFooter>
                                <Button
                                    v-if="folderBeingEdited !== null"
                                    type="button"
                                    variant="ghost"
                                    class="text-destructive"
                                    :disabled="mutationPending"
                                    @click="confirmFolderDeletion = true"
                                    >Delete folder</Button
                                >
                                <DialogClose as-child>
                                    <Button type="button" variant="secondary"
                                        >{{ t('Cancel') }}</Button
                                    >
                                </DialogClose>
                                <Button
                                    type="submit"
                                    :disabled="
                                        mutationPending || !folderName.trim()
                                    "
                                    >Save folder</Button
                                >
                            </DialogFooter>
                        </form>
                    </template>
                </DialogContent>
            </Dialog>
        </aside>
        <main class="min-w-0 space-y-4 p-4 sm:p-6">
            <header
                class="flex flex-wrap items-end justify-between gap-4 border-b pb-4"
            >
                <h2 class="text-2xl font-semibold tracking-tight">
                    {{
                        activePlaylist?.name ??
                        (selected === 'offline'
                            ? 'Available Offline'
                            : selected === 'favorites'
                              ? 'Favorites'
                              : selected === 'discover'
                                ? 'Discover'
                                : 'All media')
                    }}
                </h2>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <label class="flex items-center gap-2"
                        ><input
                            v-model="offline"
                            type="checkbox"
                            :disabled="!connected"
                        />
                        Offline mode</label
                    >
                    <a
                        v-if="offlineReady"
                        :href="offlineShell"
                        class="underline underline-offset-4"
                        >Open offline player</a
                    >
                </div>
            </header>
            <div
                v-if="selected === null && !offlineMode"
                class="grid gap-4 md:grid-cols-2"
            >
                <section
                    v-for="section in historySections"
                    :key="section.title"
                    class="rounded-lg border p-3"
                    :aria-label="section.title"
                >
                    <h3 class="mb-2 font-semibold">{{ section.title }}</h3>
                    <ul v-if="section.tracks.length">
                        <li v-for="track in section.tracks" :key="track.id">
                            <button
                                type="button"
                                class="hover:bg-accent flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm"
                                @click="player?.play(track.id, section.tracks)"
                            >
                                <Play class="size-4 shrink-0" />
                                <span class="min-w-0 flex-1 truncate">
                                    {{ mediaTrackName(track) }}
                                </span>
                                <span
                                    v-if="section.showCount"
                                    class="text-muted-foreground shrink-0 text-xs"
                                >
                                    {{ history.counts[track.id] }}
                                    {{
                                        history.counts[track.id] === 1
                                            ? 'play'
                                            : 'plays'
                                    }}
                                </span>
                            </button>
                        </li>
                    </ul>
                    <p v-else class="text-muted-foreground text-sm">
                        No playback history yet.
                    </p>
                </section>
            </div>
            <MediaDiscovery
                v-if="selected === 'discover'"
                v-model:query="discoveryQuery"
                v-model:group="discoveryGroup"
                :tracks="discoverySource"
            />
            <div v-else class="relative max-w-xs">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-2.5 left-3 size-4"
                />
                <Input
                    v-model="search"
                    type="search"
                    aria-label="Search media"
                    placeholder="Filter tracks"
                    class="pl-9"
                />
            </div>
            <label
                class="flex flex-wrap items-center gap-3 text-sm font-medium"
            >
                Discovery folder
                <select
                    :value="directoryId ?? ''"
                    :disabled="offlineMode || mutationPending"
                    class="bg-background max-w-full rounded-md border px-3 py-2"
                    @change="changeDirectory"
                >
                    <option value="">Select a folder</option>
                    <option
                        v-for="directory in directories"
                        :key="directory.id"
                        :value="directory.id"
                    >
                        {{ directory.path }}
                    </option>
                </select>
            </label>
            <label
                v-if="selected === 'discover' && directoryId !== null"
                class="flex items-center gap-2 text-sm"
            >
                <input
                    v-model="discoveryFolderOnly"
                    type="checkbox"
                    @change="discoveryGroup = null"
                />
                Only show media in this discovery folder
            </label>
            <label
                v-if="availableTags.length"
                class="flex items-center gap-2 text-sm"
            >
                Filter by tag
                <select
                    v-model="activeTag"
                    class="bg-background rounded-md border p-2"
                >
                    <option :value="null">All tags</option>
                    <option
                        v-for="tag in availableTags"
                        :key="tag.id"
                        :value="tag.id"
                    >
                        {{ tag.name }}
                    </option>
                </select>
            </label>
            <div v-if="!offlineMode" class="flex flex-wrap gap-2">
                <Button size="sm" variant="outline" @click="openSmartPlaylist()"
                    >Create smart playlist</Button
                >
                <Button
                    v-if="activePlaylist?.smart_rules"
                    size="sm"
                    variant="outline"
                    @click="openSmartPlaylist(activePlaylist)"
                    >Edit smart playlist rules</Button
                >
            </div>
            <Dialog v-model:open="smartPlaylistOpen">
                <DialogContent class="max-h-[90dvh] overflow-y-auto">
                    <DialogTitle>{{
                        smartPlaylistBeingEdited
                            ? 'Edit smart playlist'
                            : 'Create smart playlist'
                    }}</DialogTitle>
                    <DialogDescription
                        >Tracks must match every rule you set. Protected
                        metadata stays in the vault.</DialogDescription
                    >
                    <MediaSmartPlaylistForm
                        v-if="smartPlaylistOpen"
                        :playlist="smartPlaylistBeingEdited"
                        @save="saveSmartPlaylist"
                    />
                </DialogContent>
            </Dialog>
            <form
                v-if="!offlineMode"
                class="flex max-w-sm items-center gap-2"
                @submit.prevent="savePlaylist"
            >
                <Input
                    v-model="name"
                    maxlength="100"
                    aria-label="Playlist name"
                    :placeholder="
                        activePlaylist ? 'Playlist name' : 'New playlist name'
                    "
                    required
                />
                <Button
                    variant="secondary"
                    :disabled="mutationPending || !name.trim()"
                    ><Plus v-if="!activePlaylist" class="size-4" />{{
                        activePlaylist ? 'Rename' : 'Create'
                    }}</Button
                >
            </form>
            <label
                v-if="activePlaylist && !offlineMode && folderOptions.length"
                class="flex flex-wrap items-center gap-2 text-sm"
            >
                Playlist folder
                <select
                    class="bg-background rounded-md border p-2"
                    :value="activePlaylist.folder_id ?? ''"
                    :disabled="mutationPending"
                    @change="movePlaylist(activePlaylist, $event)"
                >
                    <option value="">Top level</option>
                    <option
                        v-for="folder in folderOptions"
                        :key="folder.id"
                        :value="folder.id"
                    >
                        {{ '　'.repeat(folder.depth) + folder.name }}
                    </option>
                </select>
            </label>
            <div
                v-if="activePlaylist && !offlineMode"
                class="flex flex-wrap items-center gap-3"
            >
                <MediaCover
                    :file-id="activePlaylist.cover_file_id"
                    :alt="activePlaylist.name + ' cover'"
                />
                <label class="flex items-center gap-2 text-sm">
                    Playlist cover
                    <select
                        class="bg-background rounded-md border p-2"
                        :value="activePlaylist.cover_file_id ?? ''"
                        :disabled="mutationPending"
                        @change="setPlaylistCover(activePlaylist, $event)"
                    >
                        <option value="">No cover</option>
                        <option
                            v-for="cover in artwork"
                            :key="cover.id"
                            :value="cover.id"
                        >
                            {{ cover.name }}
                        </option>
                    </select>
                </label>
                <a
                    :href="filesIndex.url()"
                    target="_blank"
                    rel="noopener"
                    class="text-sm underline"
                    >Upload artwork in Files</a
                >
                <Button
                    size="sm"
                    variant="ghost"
                    @click="router.reload({ only: ['artwork'] })"
                    >Refresh artwork</Button
                >
            </div>
            <Dialog
                v-if="activePlaylist && !offlineMode"
                v-model:open="deleteConfirmationOpen"
            >
                <DialogTrigger as-child>
                    <Button
                        variant="outline"
                        :disabled="mutationPending"
                        class="text-destructive"
                    >
                        <Trash2 class="size-4" />
                        Delete playlist
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogTitle>Delete playlist?</DialogTitle>
                    <DialogDescription>
                        Delete “{{ activePlaylist.name }}”? Your original files
                        and saved browser copies will still be available.
                    </DialogDescription>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button variant="secondary">{{ t('Cancel') }}</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            :disabled="mutationPending"
                            @click="deletePlaylist"
                        >
                            Delete playlist
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <Button
                v-if="activePlaylist && !offlineMode"
                variant="secondary"
                :disabled="
                    !!downloadRequest ||
                    !activePlaylist.file_ids.some((id) => !copyIds.has(id))
                "
                @click="saveOffline(activePlaylist.file_ids)"
            >
                <CloudDownload class="size-4" /> Save playlist offline
            </Button>
            <Button
                v-if="activePlaylist && !offlineMode"
                variant="secondary"
                :disabled="!activePlaylist.file_ids.length"
                @click="openExport(activePlaylist.file_ids)"
                >Export playlist</Button
            >
            <section
                v-if="downloadRequest || downloadResult"
                aria-label="Offline downloads"
                class="space-y-2 rounded-lg border p-3 text-sm"
            >
                <div
                    v-if="downloadRequest"
                    class="flex flex-wrap items-center gap-3"
                >
                    <p v-if="downloadProgress" role="status">
                        Saving {{ downloadProgress.position }} of
                        {{ downloadProgress.count }} ·
                        {{ downloadName(downloadProgress.id) }}
                    </p>
                    <progress
                        v-if="downloadProgress?.total"
                        aria-label="Current track download progress"
                        :value="downloadProgress.bytes"
                        :max="downloadProgress.total"
                    />
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="cancellingDownload"
                        @click="cancelDownloads"
                        >Stop saving on this browser</Button
                    >
                </div>
                <template v-if="downloadResult">
                    <p role="status">
                        {{
                            downloadResult.cancelled
                                ? 'Stopped saving on this browser. Completed copies are kept.'
                                : 'Downloads finished.'
                        }}
                        {{ downloadResult.saved.length }} saved,
                        {{ downloadResult.failures.length }} failed.
                    </p>
                    <ul
                        v-if="downloadResult.failures.length"
                        class="list-disc space-y-1 pl-5"
                    >
                        <li
                            v-for="failure in downloadResult.failures"
                            :key="failure.id"
                        >
                            {{ downloadName(failure.id) }} ·
                            {{ failure.message }}
                        </li>
                    </ul>
                </template>
            </section>
            <div
                v-if="visibleTracks.length"
                role="group"
                aria-label="Track selection and sorting"
                class="flex flex-wrap items-center gap-3 text-sm"
            >
                <label class="flex items-center gap-2">
                    Sort tracks
                    <select
                        v-model="sort"
                        class="bg-background rounded-md border p-2"
                    >
                        <option value="default">
                            {{
                                activePlaylist
                                    ? 'Playlist order'
                                    : 'Library order'
                            }}
                        </option>
                        <option value="name-asc">Name (A–Z)</option>
                        <option value="name-desc">Name (Z–A)</option>
                        <option value="rating">Highest rated</option>
                    </select>
                </label>
                <label class="flex items-center gap-2">
                    <input
                        type="checkbox"
                        :checked="allSelected"
                        :indeterminate="selectedIds.length > 0 && !allSelected"
                        @change="toggleAllTracks"
                    />
                    Select all tracks
                </label>
                <span aria-live="polite"
                    >{{ selectedIds.length }} selected</span
                >
                <Button
                    size="sm"
                    variant="secondary"
                    :disabled="!selectedIds.length"
                    @click="queueSelection"
                    >Add selected to queue</Button
                >
                <Button
                    v-if="!offlineMode"
                    size="sm"
                    variant="secondary"
                    :disabled="
                        !!downloadRequest ||
                        !selectedIds.some((id) => !copyIds.has(id))
                    "
                    @click="saveOffline(selectedIds)"
                >
                    <CloudDownload class="size-4" /> Save selected offline
                </Button>
                <Button
                    v-if="!offlineMode"
                    size="sm"
                    variant="secondary"
                    :disabled="!selectedIds.length"
                    @click="openExport(selectedIds)"
                    >Export selected</Button
                >
                <template v-if="!offlineMode && manualPlaylists.length">
                    <select
                        v-model="destination"
                        aria-label="Destination playlist"
                        class="bg-background rounded-md border p-2"
                        :disabled="
                            !selectedOriginalIds.length || mutationPending
                        "
                    >
                        <option :value="null" disabled>
                            Choose a playlist
                        </option>
                        <option
                            v-for="playlist in manualPlaylists"
                            :key="playlist.id"
                            :value="playlist.id"
                        >
                            {{ playlist.name }}
                        </option>
                    </select>
                    <Button
                        size="sm"
                        variant="secondary"
                        :disabled="
                            destination === null ||
                            !selectedOriginalIds.length ||
                            mutationPending
                        "
                        @click="saveSelection"
                        >Add selected to playlist</Button
                    >
                </template>
            </div>
            <p
                v-if="selected === 'discover'"
                class="text-muted-foreground text-sm"
                role="status"
            >
                {{ visibleTracks.length }}
                {{ visibleTracks.length === 1 ? 'track' : 'tracks' }}
            </p>
            <p
                v-if="!visibleTracks.length"
                class="text-muted-foreground rounded-xl border p-5 text-sm"
            >
                {{
                    offlineMode || selected === 'offline'
                        ? 'No offline tracks in this view.'
                        : selected === 'discover' &&
                            (discoveryQuery || discoveryGroup)
                          ? 'No tracks match your search.'
                          : directoryId
                            ? 'No media in this view.'
                            : 'Select a discovery folder to find your media.'
                }}
            </p>
            <ul v-else class="divide-y border-y">
                <li
                    v-for="(track, position) in visibleTracks"
                    :key="track.id"
                    class="hover:bg-muted/60 flex flex-wrap items-center gap-2 px-2 py-1.5"
                >
                    <input
                        v-model="selectedTracks"
                        type="checkbox"
                        :value="track.id"
                        :aria-label="'Select ' + mediaTrackName(track)"
                    />
                    <Button
                        size="icon"
                        variant="secondary"
                        :aria-label="'Play ' + mediaTrackName(track)"
                        @click="player?.play(track.id)"
                        ><Play class="size-4"
                    /></Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                size="icon"
                                variant="ghost"
                                :aria-label="
                                    'Queue actions for ' + mediaTrackName(track)
                                "
                                ><ListMusic class="size-4"
                            /></Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                            <DropdownMenuItem
                                @select="player?.enqueue(track.id, true)"
                                >Play next</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                @select="player?.enqueue(track.id)"
                                >Add to queue</DropdownMenuItem
                            >
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <div class="min-w-40 flex-1">
                        <div class="flex items-center gap-2">
                            <MediaCover
                                v-if="
                                    !track.protected &&
                                    track.details?.cover_file_id
                                "
                                :file-id="track.details.cover_file_id"
                                :alt="mediaTrackName(track) + ' cover'"
                                class="size-10"
                            />
                            <p class="text-sm font-medium break-all">
                                {{ mediaTrackName(track) }}
                            </p>
                        </div>
                        <p
                            v-if="
                                !track.protected &&
                                (track.details?.artist || track.details?.album)
                            "
                            class="text-muted-foreground text-xs"
                        >
                            {{
                                [track.details.artist, track.details.album]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </p>
                        <div
                            class="text-muted-foreground mt-1 flex flex-wrap gap-1 text-xs"
                        >
                            <span v-if="copyIds.has(track.id)"
                                >Saved offline</span
                            >
                            <span
                                v-for="tag in track.tags"
                                :key="tag.id"
                                class="inline-flex gap-1 rounded-full border px-2"
                            >
                                {{ tag.name }}
                                <button
                                    v-if="
                                        !offlineMode && tracksById.has(track.id)
                                    "
                                    :disabled="mutationPending"
                                    :aria-label="'Remove tag ' + tag.name"
                                    @click="
                                        submit(
                                            'delete',
                                            detach.url({
                                                file: track.id,
                                                tag: tag.id,
                                            }),
                                            {},
                                        )
                                    "
                                >
                                    <X class="size-3" />
                                </button>
                            </span>
                        </div>
                    </div>
                    <template v-if="canReorder && visibleTracks.length > 1">
                        <Button
                            size="icon"
                            variant="ghost"
                            :aria-label="'Move up: ' + mediaTrackName(track)"
                            :aria-disabled="mutationPending || position === 0"
                            @click="reorderPlaylistTrack(track.id, -1)"
                            ><ArrowUp class="size-4"
                        /></Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            :aria-label="'Move down: ' + mediaTrackName(track)"
                            :aria-disabled="
                                mutationPending ||
                                position === visibleTracks.length - 1
                            "
                            @click="reorderPlaylistTrack(track.id, 1)"
                            ><ArrowDown class="size-4"
                        /></Button>
                    </template>
                    <Button
                        v-if="!offlineMode && tracksById.has(track.id)"
                        size="icon"
                        variant="ghost"
                        :aria-label="
                            'Edit details for ' + mediaTrackName(track)
                        "
                        @click="openTrackDetails(track)"
                        ><Info class="size-4"
                    /></Button>
                    <Button
                        v-if="!offlineMode"
                        size="icon"
                        variant="ghost"
                        aria-label="Save offline"
                        :disabled="!!downloadRequest || copyIds.has(track.id)"
                        @click="saveOffline([track.id])"
                        ><CloudDownload class="size-4"
                    /></Button>
                    <Button
                        size="icon"
                        variant="ghost"
                        :aria-pressed="track.favorite"
                        aria-label="Favorite"
                        :disabled="
                            offlineMode ||
                            mutationPending ||
                            !tracksById.has(track.id)
                        "
                        @click="
                            submit('patch', updateTrack.url(track.id), {
                                favorite: !track.favorite,
                            })
                        "
                        ><Heart
                            class="size-4"
                            :class="{
                                'fill-red-500 text-red-500': track.favorite,
                            }"
                    /></Button>
                    <select
                        :value="track.rating ?? ''"
                        aria-label="Rating"
                        class="bg-background rounded-md border p-2 text-sm"
                        :disabled="
                            offlineMode ||
                            mutationPending ||
                            !tracksById.has(track.id)
                        "
                        @change="
                            submit('patch', updateTrack.url(track.id), {
                                rating:
                                    Number(
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    ) || null,
                            })
                        "
                    >
                        <option value="">Rate</option>
                        <option
                            v-for="rating in 10"
                            :key="rating"
                            :value="rating"
                        >
                            {{ rating }} / 10
                        </option>
                    </select>
                    <Button
                        v-if="
                            !offlineMode &&
                            !track.protected &&
                            tracksById.has(track.id)
                        "
                        size="icon"
                        variant="ghost"
                        aria-label="Tag track"
                        @click="
                            tagEditor = track.id;
                            tagName = '';
                        "
                        ><Tag class="size-4"
                    /></Button>
                    <select
                        v-if="
                            !offlineMode &&
                            manualPlaylists.length &&
                            tracksById.has(track.id)
                        "
                        aria-label="Add to playlist"
                        class="bg-background rounded-md border p-2 text-sm"
                        :disabled="mutationPending"
                        @change="addToPlaylist(track, $event)"
                    >
                        <option value="">Add to playlist</option>
                        <option
                            v-for="playlist in manualPlaylists"
                            :key="playlist.id"
                            :value="playlist.id"
                        >
                            {{ playlist.name }}
                        </option>
                    </select>
                    <Button
                        v-if="
                            activePlaylist &&
                            !activePlaylist.smart_rules &&
                            !offlineMode
                        "
                        size="icon"
                        variant="ghost"
                        aria-label="Remove from playlist"
                        :disabled="mutationPending"
                        @click="
                            submit(
                                'patch',
                                updatePlaylist.url(activePlaylist.id),
                                {
                                    remove_file_id: track.id,
                                },
                            )
                        "
                        ><X class="size-4"
                    /></Button>
                    <form
                        v-if="tagEditor === track.id && !offlineMode"
                        class="flex w-full gap-2"
                        @submit.prevent="
                            submit(
                                'post',
                                attach.url(track.id),
                                { tags: [tagName] },
                                () => {
                                    tagEditor = null;
                                },
                            )
                        "
                    >
                        <Input
                            v-model="tagName"
                            aria-label="Tag name"
                            maxlength="50"
                            required
                        />
                        <Button type="submit" :disabled="mutationPending"
                            >Add tag</Button
                        >
                    </form>
                </li>
            </ul>
            <Dialog v-model:open="exportOpen">
                <MediaExportDialog
                    v-if="exportOpen"
                    :account="account"
                    :ids="exportIds"
                    :encrypted="
                        exportIds.some((id) => tracksById.get(id)?.encrypted)
                    "
                    :names="exportNames"
                />
            </Dialog>
            <Dialog v-model:open="trackDetailsOpen">
                <MediaTrackDetails
                    v-if="trackDetailsOpen && trackForDetails"
                    :track="trackForDetails"
                    :account="account"
                    :artwork="artwork"
                    :saved-copy="copyIds.has(trackForDetails.id)"
                    @saved="onTrackDetailsSaved"
                />
            </Dialog>
        </main>
        <div
            class="bg-background sticky bottom-0 z-10 min-w-0 border-t lg:col-span-2"
        >
            <MediaPlayback
                ref="player"
                docked
                :account="account"
                :tracks="visibleTracks"
                :copies="copies"
                :offline="offlineMode"
                :remote-url="remotePlaybackUrl"
                :fallback-remote-url="
                    (id, signal) => preparedDownloadUrl(id, signal, true)
                "
                :remote-encrypted="remoteEncrypted"
                @played="recordPlayback"
            />
        </div>
    </div>
</template>
