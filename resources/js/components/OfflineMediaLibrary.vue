<script setup lang="ts">
import {
    AudioLines,
    Download,
    Film,
    ListMusic,
    Music2,
    Play,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import MediaThemePicker from './MediaThemePicker.vue';
import type { MediaTheme } from '@/lib/mediaTheme';
import MediaPlayback from '@/components/MediaPlayback.vue';
import {
    browserAccount,
    browserLogoutEpoch,
    cachedMediaPlaylists,
    listBrowserCopies,
    onBrowserCopiesChanged,
    type BrowserCopy,
} from '@/lib/browserDownloads';

import { playableMediaCopies } from '@/lib/mediaDownloads';
import {
    syncMediaPlayback,
    queueMediaPlayback,
} from '@/lib/mediaPlaybackHistory';
import { playback } from '@/routes/media/tracks';

const props = defineProps<{ account: number }>();
const mediaTheme = ref<MediaTheme>('default');
const player = ref<InstanceType<typeof MediaPlayback> | null>(null);
let logoutEpoch = browserLogoutEpoch(props.account);
const copies = ref<BrowserCopy[]>([]);
const playlists = ref(cachedMediaPlaylists(props.account));
const selected = ref<number | null>(null);
const tracks = computed(() => {
    const media = playableMediaCopies(copies.value);
    if (selected.value === null) return media;
    const ids =
        playlists.value.find((playlist) => playlist.id === selected.value)
            ?.file_ids ?? [];
    const byId = new Map(media.map((copy) => [copy.id, copy]));
    return ids.flatMap((id) => byId.get(id) ?? []);
});
let unsubscribe: (() => void) | undefined;
async function syncHistory(): Promise<void> {
    try {
        await syncMediaPlayback(window.localStorage, props.account, (fileId) =>
            playback.url(fileId),
        );
    } catch {
        // Offline playback remains available if history cannot be synchronized.
    }
}
function recordPlayback(fileId: number): void {
    try {
        queueMediaPlayback(window.localStorage, props.account, {
            fileId,
            eventId: crypto.randomUUID(),
        });
    } catch {
        return;
    }
    void syncHistory();
}
async function refresh(): Promise<void> {
    if (browserAccount() !== props.account) {
        player.value?.stop();
        copies.value = [];
        playlists.value = [];
        return;
    }
    const epoch = browserLogoutEpoch(props.account);
    if (epoch !== logoutEpoch) {
        logoutEpoch = epoch;
        player.value?.stop();
    }
    copies.value = await listBrowserCopies(props.account).catch(() => []);
    playlists.value = cachedMediaPlaylists(props.account);
}
onMounted(() => {
    unsubscribe = onBrowserCopiesChanged(() => void refresh());
    void refresh();
    window.addEventListener('online', syncHistory);
    void syncHistory();
});
onBeforeUnmount(() => {
    unsubscribe?.();
    window.removeEventListener('online', syncHistory);
});
</script>

<template>
    <section
        :data-media-theme="mediaTheme"
        class="grid gap-3 text-zinc-100 md:grid-cols-[15rem_minmax(0,1fr)]"
        aria-label="Offline media player"
    >
        <aside
            class="space-y-4 rounded-2xl border border-white/10 bg-[var(--media-theme-hero,#151a18)] p-4"
        >
            <h2 class="flex items-center gap-2 font-semibold">
                <ListMusic
                    class="size-5 text-[var(--media-theme-highlight,var(--color-emerald-300))]"
                />
                Your library
            </h2>
            <MediaThemePicker v-model="mediaTheme" :account="account" />
            <nav
                class="flex gap-2 overflow-x-auto md:flex-col"
                aria-label="Playlists"
            >
                <button
                    type="button"
                    class="flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-left text-sm hover:bg-white/10"
                    :class="{
                        'bg-white/10 text-[var(--media-theme-highlight,var(--color-emerald-300))]':
                            selected === null,
                    }"
                    :aria-pressed="selected === null"
                    @click="selected = null"
                >
                    <Download class="size-4" /> All saved media
                </button>
                <button
                    v-for="playlist in playlists"
                    :key="playlist.id"
                    type="button"
                    class="flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-left text-sm hover:bg-white/10"
                    :class="{
                        'bg-white/10 text-[var(--media-theme-highlight,var(--color-emerald-300))]':
                            selected === playlist.id,
                    }"
                    :aria-pressed="selected === playlist.id"
                    @click="selected = playlist.id"
                >
                    <ListMusic class="size-4 shrink-0" /><span
                        class="truncate"
                        >{{ playlist.name }}</span
                    >
                </button>
            </nav>
        </aside>
        <div
            class="min-w-0 space-y-4 overflow-hidden rounded-2xl border border-white/10 bg-[var(--media-theme-hero,#101412)] p-3 sm:p-5"
        >
            <header
                class="flex items-end gap-4 rounded-xl bg-linear-to-br from-[var(--media-theme-hero,var(--color-emerald-800))] to-[#182520] p-5 sm:p-8"
            >
                <div
                    class="grid size-14 shrink-0 place-items-center rounded-xl bg-[var(--media-theme-highlight,var(--color-emerald-300))] text-emerald-950 sm:size-20"
                >
                    <AudioLines class="size-7 sm:size-9" />
                </div>
                <div class="min-w-0">
                    <p class="text-sm text-emerald-100">Offline Media Player</p>
                    <h1 class="truncate text-2xl font-bold sm:text-4xl">
                        {{
                            playlists.find(
                                (playlist) => playlist.id === selected,
                            )?.name ?? 'All saved media'
                        }}
                    </h1>
                </div>
            </header>
            <MediaPlayback
                ref="player"
                :account="account"
                :tracks="tracks"
                :copies="copies"
                offline
                @played="recordPlayback"
                class="border-white/10 bg-[var(--media-theme-hero,#191f1c)]"
            />
            <ul v-if="tracks.length" class="divide-y divide-white/10">
                <li
                    v-for="track in tracks"
                    :key="track.id"
                    class="flex items-center"
                >
                    <button
                        type="button"
                        class="group flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left hover:bg-white/10"
                        @click="player?.play(track.id)"
                    >
                        <span
                            class="grid size-10 shrink-0 place-items-center rounded-lg bg-white/10 text-[var(--media-theme-highlight,var(--color-emerald-200))]"
                        >
                            <Play
                                class="hidden size-4 group-hover:block group-focus-visible:block"
                            />
                            <component
                                :is="
                                    track.mime_type.startsWith('video/')
                                        ? Film
                                        : Music2
                                "
                                class="size-4 group-hover:hidden group-focus-visible:hidden"
                            />
                        </span>
                        <span class="min-w-0 truncate text-sm font-medium">{{
                            track.metadata_protected
                                ? 'Protected file ' + track.id
                                : track.name
                        }}</span>
                    </button>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                class="rounded p-2 hover:bg-white/10"
                                aria-label="Queue actions"
                            >
                                <ListMusic class="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent>
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
                </li>
            </ul>
            <p v-else class="px-2 py-4 text-sm text-zinc-400">
                No playable files are saved in this view.
            </p>
        </div>
    </section>
</template>
