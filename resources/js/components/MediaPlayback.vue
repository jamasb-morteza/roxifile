<script setup lang="ts">
import {
    ArrowDown,
    ArrowUp,
    Headphones,
    X,
    ListMusic,
    Play,
    Repeat,
    Repeat1,
    Shuffle,
    SkipBack,
    SkipForward,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import MediaControls from '@/components/MediaControls.vue';
import { mediaShortcut } from '@/lib/mediaShortcuts';
import { Button } from '@/components/ui/button';
import { readBrowserCopy, type BrowserCopy } from '@/lib/browserDownloads';
import {
    decryptBrowserCopy,
    unlockBrowserCopy,
} from '@/lib/encryptedBrowserCopy';
import type { StagedFile } from '@/lib/contentsEncryption';
import {
    availableQueue,
    enqueueTracks,
    moveQueuedTrack,
    nextTrack,
    playbackQueue,
    upcomingQueue,
    type RepeatMode,
} from '@/lib/mediaQueue';

import { crossfadeCandidate, crossfadeProgress } from '@/lib/mediaCrossfade';
import { mediaTrackMayUseRemote } from '@/lib/mediaDownloads';

type Track = {
    id: number;
    name: string;
    mime_type: string;
    encrypted?: boolean;
    streamable?: boolean;
    localOnly?: boolean;
};
const props = defineProps<{
    account: number;
    tracks: Track[];
    copies: BrowserCopy[];
    offline: boolean;
    docked?: boolean;
    remoteUrl?: (id: number, signal: AbortSignal) => Promise<string>;
    fallbackRemoteUrl?: (id: number, signal: AbortSignal) => Promise<string>;
    remoteEncrypted?: (
        id: number,
        signal: AbortSignal,
    ) => Promise<{ copy: BrowserCopy; load: () => Promise<StagedFile> }>;
}>();
const emit = defineEmits<{
    played: [trackId: number];
}>();
const current = ref<Track | null>(null);
const mime = ref('');
const source = ref('');
const crossfadePreference = 'roxifile.media-crossfade';
const crossfadeSeconds = ref(0);
const standbySource = ref('');
const playbackSources = computed(() => [
    { url: source.value, id: current.value?.id, active: true },
    ...(standbySource.value && staged
        ? [{ url: standbySource.value, id: staged.track.id, active: false }]
        : []),
]);
const standbyMedia = ref<HTMLAudioElement | null>(null);
let staged:
    | {
          track: Track;
          controller: AbortController;
          source: string;
          release?: () => void;
      }
    | undefined;
let fading:
    | {
          outgoing: HTMLMediaElement;
          incoming: HTMLAudioElement;
          volume: number;
          seconds: number;
          frame: number;
          started: boolean;
      }
    | undefined;
const error = ref('');
const busy = ref(false);
const streamingSource = ref(false);
let resumeAfterFallback: number | null = null;
const shuffle = ref(false);
const repeat = ref<RepeatMode>('off');
const queue = ref<number[]>([]);
let unshuffled: number[] = [];
const showQueue = ref(false);
const queuedTracks = computed(() =>
    queue.value.flatMap((id) => queueTracks.get(id) ?? []),
);
const queueTracks = new Map<number, Track>();
const media = ref<HTMLMediaElement | null>(null);
const controls = ref<InstanceType<typeof MediaControls> | null>(null);
const locked = ref<BrowserCopy | null>(null);
const password = ref('');
const recovery = ref(false);
let request: AbortController | undefined;
let reportedPlayback = false;
let release: (() => void) | undefined;
let loadCiphertext: (() => Promise<StagedFile>) | undefined;
const trackIndex = computed(() => queue.value.indexOf(current.value?.id ?? -1));

function setPlaybackElement(element: unknown, active: boolean): void {
    if (!(element instanceof HTMLMediaElement)) return;
    if (active) media.value = element;
    else standbyMedia.value = element as HTMLAudioElement;
}

function stop(): void {
    cancelCrossfade();
    request?.abort();
    request = undefined;
    streamingSource.value = false;
    resumeAfterFallback = null;
    current.value = null;
    error.value = '';
    media.value?.pause();
    source.value = '';
    release?.();
    release = undefined;
    locked.value = null;
    loadCiphertext = undefined;
    password.value = '';
    recovery.value = false;
    busy.value = false;
}

async function autoplay(): Promise<void> {
    await nextTick();
    await media.value?.play().catch(() => {});
}

function useBlob(
    blob: Blob,
    type: string,
    dispose?: () => Promise<void>,
): void {
    mime.value = type;
    const url = URL.createObjectURL(blob.slice(0, blob.size, type));
    source.value = url;
    release = () => {
        URL.revokeObjectURL(url);
        void dispose?.();
    };
}

function recordPlaying(event: Event): void {
    if (event.target === media.value) reportPlayback();
}

function reportPlayback(): void {
    if (!current.value || !source.value || reportedPlayback) return;
    reportedPlayback = true;
    emit('played', current.value.id);
}

async function open(track: Track, preservePlayback = false): Promise<void> {
    stop();
    if (!preservePlayback) reportedPlayback = false;
    current.value = track;
    mime.value = track.mime_type;
    error.value = '';
    busy.value = true;
    const controller = new AbortController();
    request = controller;
    const copy = props.copies.find(
        (item) => item.id === track.id && item.available,
    );
    try {
        const file = copy
            ? await readBrowserCopy(props.account, copy.id).catch(() => null)
            : null;
        controller.signal.throwIfAborted();
        if (file && copy) {
            if (copy.encryption_header) {
                loadCiphertext = async () => ({
                    file,
                    dispose: async () => {},
                });
                locked.value = copy;
                return;
            }
            useBlob(file, copy.mime_type);
        } else {
            if (
                !mediaTrackMayUseRemote(track, props.offline) ||
                !props.remoteUrl
            ) {
                throw new Error(
                    track.localOnly
                        ? 'This track’s browser copy is unavailable.'
                        : 'Save this track for offline playback while connected.',
                );
            }
            if (track.encrypted && props.remoteEncrypted) {
                const encrypted = await props.remoteEncrypted(
                    track.id,
                    controller.signal,
                );
                controller.signal.throwIfAborted();
                loadCiphertext = encrypted.load;
                locked.value = encrypted.copy;
                return;
            }
            streamingSource.value = track.streamable === true && !track.encrypted;
            source.value = await props
                .remoteUrl(track.id, controller.signal)
                .then((url) => {
                    controller.signal.throwIfAborted();
                    return url;
                });
        }
        await autoplay();
    } catch (cause) {
        if (!controller.signal.aborted)
            error.value =
                cause instanceof Error ? cause.message : 'Playback failed.';
    } finally {
        if (request === controller) busy.value = false;
    }
}

async function fallbackToPreparedDownload(failedUrl: string): Promise<void> {
    if (!current.value || source.value !== failedUrl) return;
    if (!streamingSource.value || !props.fallbackRemoteUrl) {
        error.value = 'This file could not be played in your browser.';
        return;
    }

    streamingSource.value = false;
    const controller = request;
    const trackId = current.value.id;
    if (!controller || controller.signal.aborted) return;
    resumeAfterFallback = media.value?.currentTime ?? 0;
    busy.value = true;
    try {
        const url = await props.fallbackRemoteUrl(trackId, controller.signal);
        if (request !== controller || controller.signal.aborted || current.value?.id !== trackId) return;
        source.value = url;
        await autoplay();
    } catch (cause) {
        if (!controller.signal.aborted && request === controller) {
            error.value = cause instanceof Error ? cause.message : 'Playback failed.';
        }
    } finally {
        if (request === controller) busy.value = false;
    }
}

function restorePlaybackPosition(): void {
    if (resumeAfterFallback === null || !media.value) return;
    const position = resumeAfterFallback;
    resumeAfterFallback = null;
    if (Number.isFinite(media.value.duration) && media.value.duration > 0) {
        media.value.currentTime = Math.min(position, Math.max(0, media.value.duration - 0.25));
    }
}

async function unlock(): Promise<void> {
    const copy = locked.value;
    const controller = request;
    const load = loadCiphertext;
    if (!copy || !controller || !load || busy.value) return;
    busy.value = true;
    error.value = '';
    const credential = password.value;
    password.value = '';
    let ciphertext: StagedFile | undefined;
    try {
        const { key, metadata } = await unlockBrowserCopy(
            copy,
            props.account,
            credential,
            recovery.value,
        );
        controller.signal.throwIfAborted();
        const type = metadata?.mime ?? copy.mime_type;
        if (!/^(audio|video)\//.test(type))
            throw new Error('This protected file is not audio or video.');
        ciphertext = await load();
        const clear = await decryptBrowserCopy(
            copy,
            props.account,
            key,
            ciphertext.file,
            controller.signal,
        );
        if (controller.signal.aborted) {
            await clear.dispose();
            return;
        }
        if (metadata)
            current.value = {
                ...current.value,
                id: copy.id,
                name: metadata.name,
                mime_type: metadata.mime,
            };
        useBlob(clear.file, type, clear.dispose);
        locked.value = null;
        await autoplay();
    } catch (cause) {
        if (!controller.signal.aborted)
            error.value =
                cause instanceof Error
                    ? cause.message
                    : 'Could not unlock this track.';
    } finally {
        await ciphertext?.dispose();
        if (request === controller) busy.value = false;
    }
}

function play(id: number, tracks = props.tracks): void {
    const track = tracks.find((item) => item.id === id);
    if (!track) return;
    queueTracks.clear();
    for (const item of tracks) queueTracks.set(item.id, item);
    unshuffled = [...queueTracks.keys()];
    queue.value = playbackQueue(unshuffled, id, shuffle.value);
    void open(track);
}

function enqueue(id: number, playNext = false): void {
    enqueueSelection([id], playNext);
}

function enqueueSelection(ids: number[], playNext = false): void {
    const tracks = new Map(props.tracks.map((track) => [track.id, track]));
    const availableCopies = props.offline
        ? new Set(
              props.copies
                  .filter((copy) => copy.available)
                  .map((copy) => copy.id),
          )
        : null;
    const additions: number[] = [];
    for (const id of ids) {
        const track = tracks.get(id);
        if (track && (!availableCopies || availableCopies.has(id))) {
            queueTracks.set(id, track);
            additions.push(id);
        }
    }
    if (!additions.length) return;

    queue.value = enqueueTracks(
        queue.value,
        current.value?.id,
        additions,
        playNext,
    );
    unshuffled = enqueueTracks(
        unshuffled,
        current.value?.id,
        additions,
        playNext,
    );
    showQueue.value = true;
}

function removeQueued(id: number): void {
    if (queue.value.indexOf(id) <= trackIndex.value) return;
    queue.value = queue.value.filter((item) => item !== id);
    unshuffled = unshuffled.filter((item) => item !== id);
    queueTracks.delete(id);
}

const upcoming = computed(() =>
    upcomingQueue(queue.value, current.value?.id, false),
);

function moveUpcoming(id: number, direction: -1 | 1): void {
    queue.value = moveQueuedTrack(
        queue.value,
        current.value?.id,
        id,
        direction,
    );
    if (!shuffle.value) unshuffled = [...queue.value];
}

function clearUpcoming(): void {
    for (const id of upcoming.value) queueTracks.delete(id);
    queue.value = upcomingQueue(queue.value, current.value?.id, true);
    unshuffled = unshuffled.filter((id) => queueTracks.has(id));
}

function advance(ended = false): void {
    if (!current.value) {
        const first = queueTracks.get(queue.value[0]);
        if (first) void open(first);
        return;
    }
    const id = nextTrack(
        queue.value,
        current.value.id,
        ended ? repeat.value : repeat.value === 'playlist' ? 'playlist' : 'off',
    );
    if (
        ended &&
        id === staged?.track.id &&
        fading?.started &&
        !fading.incoming.paused &&
        !fading.incoming.ended &&
        !fading.incoming.error
    ) {
        completeCrossfade();
        return;
    }
    if (ended && id === current.value.id && media.value) {
        media.value.currentTime = 0;
        reportedPlayback = false;
        void autoplay();
        return;
    }
    const track = id === null ? undefined : queueTracks.get(id);
    if (track) void open(track);
}

const crossfadeTrack = computed(() =>
    crossfadeCandidate(
        current.value
            ? nextTrack(queue.value, current.value.id, repeat.value)
            : null,
        current.value?.id,
        mime.value,
        crossfadeSeconds.value,
        queueTracks,
        props.copies,
        props.offline,
    ),
);

function cancelCrossfade(): void {
    interruptCrossfade();
    staged?.controller.abort();
    staged?.release?.();
    staged = undefined;
    standbySource.value = '';
    standbyMedia.value = null;
}

function completeCrossfade(): void {
    if (!fading || !staged) return;
    const { outgoing, incoming, volume, frame } = fading;
    cancelAnimationFrame(frame);
    const previousRelease = release;
    request?.abort();
    request = staged.controller;
    streamingSource.value = staged.track.streamable === true && !staged.track.encrypted && !staged.release;
    resumeAfterFallback = null;
    release = staged.release;
    current.value = staged.track;
    mime.value = staged.track.mime_type;
    source.value = staged.source;
    standbySource.value = '';
    staged = undefined;
    fading = undefined;
    standbyMedia.value = null;
    media.value = incoming;
    outgoing.pause();
    incoming.volume = volume;
    previousRelease?.();
    reportedPlayback = false;
    reportPlayback();
}

async function prepareCrossfade(track: Track): Promise<void> {
    const controller = new AbortController();
    const preparation = {
        track,
        controller,
        source: '',
        release: undefined as (() => void) | undefined,
    };
    staged = preparation;
    try {
        const copy = props.copies.find(
            (item) => item.id === track.id && item.available,
        );
        const file = copy
            ? await readBrowserCopy(props.account, track.id).catch(() => null)
            : null;
        controller.signal.throwIfAborted();
        if (file && copy) {
            if (!copy.mime_type.startsWith('audio/')) return;
            const url = URL.createObjectURL(
                file.slice(0, file.size, copy.mime_type),
            );
            preparation.source = url;
            preparation.release = () => URL.revokeObjectURL(url);
        } else if (
            mediaTrackMayUseRemote(track, props.offline) &&
            props.remoteUrl
        ) {
            preparation.source = await props.remoteUrl(
                track.id,
                controller.signal,
            );
        }
        controller.signal.throwIfAborted();
        standbySource.value = preparation.source;
    } catch {
        // The normal transition will resolve or report errors for this track.
    }
}

watch(
    [
        crossfadeTrack,
        source,
        () => props.offline,
        () => props.copies,
        crossfadeSeconds,
    ],
    ([track]) => {
        cancelCrossfade();
        if (track && source.value) void prepareCrossfade(track);
    },
);

function startCrossfade(event: Event): void {
    const outgoing = event.currentTarget as HTMLMediaElement;
    if (
        outgoing !== media.value ||
        outgoing.paused ||
        outgoing.seeking ||
        fading ||
        !staged ||
        staged.track !== crossfadeTrack.value
    )
        return;
    const incoming = standbyMedia.value;
    if (
        !incoming ||
        incoming.getAttribute('src') !== staged.source ||
        incoming.readyState < 3 ||
        incoming.error ||
        !crossfadeSeconds.value
    )
        return;
    const remaining = outgoing.duration - outgoing.currentTime;
    if (
        !Number.isFinite(remaining) ||
        remaining <= 0 ||
        remaining > crossfadeSeconds.value
    )
        return;
    if (!Number.isFinite(incoming.duration) || incoming.duration <= remaining)
        return;

    incoming.currentTime = 0;
    incoming.muted = outgoing.muted;
    incoming.volume = 0;
    if (incoming.volume !== 0) return;
    const transition = {
        outgoing,
        incoming,
        volume: outgoing.volume,
        seconds: remaining,
        frame: 0,
        started: false,
    };
    fading = transition;
    void incoming
        .play()
        .then(() => {
            if (fading !== transition) return;
            transition.started = true;
            const fade = (): void => {
                if (fading !== transition) return;
                if (
                    (outgoing.paused && !outgoing.ended) ||
                    outgoing.seeking ||
                    incoming.error ||
                    incoming.ended ||
                    incoming.paused ||
                    staged?.track !== crossfadeTrack.value
                ) {
                    interruptCrossfade();
                    return;
                }
                const progress = crossfadeProgress(
                    outgoing.duration - outgoing.currentTime,
                    transition.seconds,
                );
                outgoing.volume = transition.volume * (1 - progress);
                incoming.volume = transition.volume * progress;
                incoming.muted = outgoing.muted;
                if (progress === 1) completeCrossfade();
                else transition.frame = requestAnimationFrame(fade);
            };
            fade();
        })
        .catch(() => {
            if (fading === transition) cancelCrossfade();
        });
}

function interruptCrossfade(): void {
    if (fading) {
        cancelAnimationFrame(fading.frame);
        fading.outgoing.volume = fading.volume;
        fading.incoming.pause();
        fading = undefined;
    }
}

function previous(): void {
    const track = queueTracks.get(queue.value[trackIndex.value - 1]);
    if (track) void open(track);
}

function toggleShuffle(): void {
    shuffle.value = !shuffle.value;
    if (!shuffle.value) {
        const prefix = upcomingQueue(queue.value, current.value?.id, true);
        const remaining = new Set(upcoming.value);
        queue.value = [
            ...prefix,
            ...unshuffled.filter((id) => remaining.has(id)),
        ];
        return;
    }
    unshuffled = [...queue.value];
    if (upcoming.value.length > 1) {
        const items = upcoming.value;
        const first = items[Math.floor(Math.random() * items.length)];
        queue.value = [
            ...upcomingQueue(queue.value, current.value?.id, true),
            ...playbackQueue(items, first, true),
        ];
    }
}

function toggleRepeat(): void {
    repeat.value =
        repeat.value === 'off'
            ? 'playlist'
            : repeat.value === 'playlist'
              ? 'track'
              : 'off';
}

watch(
    () => [props.offline, props.copies] as const,
    ([offline, copies], [wasOffline]) => {
        if (!offline) return;
        const available = new Set(
            copies.filter((copy) => copy.available).map((copy) => copy.id),
        );
        queue.value = availableQueue(queue.value, available);
        unshuffled = availableQueue(unshuffled, available);
        for (const id of queueTracks.keys()) {
            if (!available.has(id)) queueTracks.delete(id);
        }
        const track = current.value;
        if (!track) return;
        if (!available.has(track.id)) stop();
        else if (!wasOffline) void open(track, true);
    },
);
function reset(): void {
    stop();
    queue.value = [];
    unshuffled = [];
    queueTracks.clear();
    showQueue.value = false;
}

function skipSeconds(seconds: number): void {
    const element = media.value;
    if (element && Number.isFinite(element.duration)) {
        element.currentTime = Math.max(
            0,
            Math.min(element.duration, element.currentTime + seconds),
        );
    }
}

function onKey(event: KeyboardEvent): void {
    const target = event.target;
    if (document.querySelector('[role="dialog"][data-state="open"]')) return;
    const action = mediaShortcut(
        event,
        target instanceof HTMLElement &&
            (target.isContentEditable ||
                !!target.closest(
                    'input, textarea, select, button, a, [role="menuitem"], [role="textbox"]',
                )),
    );
    if (!action || !media.value) return;
    event.preventDefault();
    switch (action) {
        case 'toggle':
            void controls.value?.togglePlay();
            break;
        case 'next':
            advance();
            break;
        case 'previous':
            previous();
            break;
        case 'mute':
            controls.value?.toggleMute();
            break;
        case 'back':
            skipSeconds(-10);
            break;
        case 'forward':
            skipSeconds(10);
            break;
        case 'louder':
            controls.value?.setVolume(media.value.volume + 0.1);
            break;
        case 'quieter':
            controls.value?.setVolume(media.value.volume - 0.1);
            break;
    }
}

const mediaActions: Partial<
    Record<MediaSessionAction, MediaSessionActionHandler>
> = {
    play: () => {
        void autoplay();
    },
    pause: () => media.value?.pause(),
    nexttrack: () => advance(),
    previoustrack: previous,
    seekbackward: ({ seekOffset }) => skipSeconds(-(seekOffset ?? 10)),
    seekforward: ({ seekOffset }) => skipSeconds(seekOffset ?? 10),
    seekto: ({ seekTime }) => {
        if (media.value && seekTime !== undefined)
            skipSeconds(seekTime - media.value.currentTime);
    },
};

watch(media, (element, _, onCleanup) => {
    if (!element || !navigator.mediaSession) return;
    const supported: MediaSessionAction[] = [];
    for (const [action, handler] of Object.entries(mediaActions)) {
        try {
            navigator.mediaSession.setActionHandler(
                action as MediaSessionAction,
                handler,
            );
            supported.push(action as MediaSessionAction);
        } catch {
            // Media session actions vary by browser.
        }
    }
    onCleanup(() => {
        for (const action of supported)
            navigator.mediaSession.setActionHandler(action, null);
    });
});
onMounted(() => {
    window.addEventListener('keydown', onKey);
    try {
        const saved = Number(localStorage.getItem(crossfadePreference));
        if ([2, 4, 6, 8].includes(saved)) crossfadeSeconds.value = saved;
    } catch {
        // The setting still applies for this player.
    }
});
watch(crossfadeSeconds, (seconds) => {
    try {
        localStorage.setItem(crossfadePreference, String(seconds));
    } catch {
        // The setting still applies for this player.
    }
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

watch(() => props.account, reset);
onBeforeUnmount(stop);
defineExpose({ play, stop: reset, enqueue, enqueueSelection });
</script>

<template>
    <section
        class="bg-card space-y-2 p-3"
        :class="
            docked
                ? 'rounded-none'
                : 'border-border rounded-2xl border shadow-sm'
        "
        aria-label="Playback"
    >
        <div class="flex items-center gap-3">
            <div
                class="bg-primary text-primary-foreground grid size-11 shrink-0 place-items-center rounded-xl"
            >
                <Headphones class="size-5" />
            </div>
            <div class="min-w-0 flex-1">
                <p class="truncate font-semibold">
                    {{ current?.name ?? 'Now playing' }}
                </p>
                <p
                    v-if="busy"
                    class="text-muted-foreground text-sm"
                    role="status"
                >
                    Preparing media…
                </p>
                <p v-else-if="!current" class="text-muted-foreground text-sm">
                    Select a track to play
                </p>
            </div>
            <Button
                variant="ghost"
                size="icon"
                aria-label="Playback queue"
                :aria-expanded="showQueue"
                @click="showQueue = !showQueue"
                ><ListMusic class="size-4"
            /></Button>
            <Button
                variant="ghost"
                size="icon"
                aria-label="Shuffle"
                :aria-pressed="shuffle"
                :class="{ 'text-primary bg-accent': shuffle }"
                @click="toggleShuffle"
                ><Shuffle class="size-4"
            /></Button>
            <Button
                variant="ghost"
                size="icon"
                aria-label="Previous track"
                :disabled="trackIndex <= 0"
                @click="previous"
                ><SkipBack class="size-4"
            /></Button>
            <Button
                variant="ghost"
                size="icon"
                aria-label="Next track"
                :disabled="!queue.length"
                @click="advance()"
                ><SkipForward class="size-4"
            /></Button>
            <Button
                variant="ghost"
                size="icon"
                :aria-label="`Repeat: ${repeat}`"
                :title="`Repeat: ${repeat}`"
                :class="{ 'text-primary bg-accent': repeat !== 'off' }"
                @click="toggleRepeat"
                ><Repeat1 v-if="repeat === 'track'" class="size-4" /><Repeat
                    v-else
                    class="size-4"
            /></Button>
        </div>
        <label
            class="text-muted-foreground flex w-fit items-center gap-2 text-xs"
        >
            Crossfade
            <select
                v-model.number="crossfadeSeconds"
                class="bg-background text-foreground rounded-md border px-2 py-1"
            >
                <option :value="0">Off</option>
                <option
                    v-for="seconds in [2, 4, 6, 8]"
                    :key="seconds"
                    :value="seconds"
                >
                    {{ seconds }} seconds
                </option>
            </select>
        </label>
        <div
            v-if="showQueue"
            class="bg-popover max-h-64 overflow-auto border-y p-3"
            :class="{ 'absolute inset-x-0 bottom-full shadow-lg': docked }"
            aria-label="Playback queue"
        >
            <p
                v-if="!queuedTracks.length"
                class="text-muted-foreground text-sm"
            >
                Play a track to start the queue.
            </p>
            <div v-else class="space-y-1">
                <Button
                    v-if="upcoming.length"
                    variant="ghost"
                    size="sm"
                    @click="clearUpcoming"
                    >Clear upcoming</Button
                >
                <ol class="space-y-1">
                    <li
                        v-for="track in queuedTracks"
                        :key="track.id"
                        class="flex items-center"
                    >
                        <button
                            type="button"
                            class="hover:bg-accent block w-full truncate rounded px-3 py-2 text-left text-sm"
                            :aria-current="
                                current?.id === track.id ? 'true' : undefined
                            "
                            :class="{
                                'font-semibold text-rose-500':
                                    current?.id === track.id,
                            }"
                            @click="open(track)"
                        >
                            {{ track.name }}
                        </button>
                        <template v-if="upcoming.includes(track.id)">
                            <Button
                                size="icon"
                                variant="ghost"
                                :aria-label="'Move up: ' + track.name"
                                :disabled="upcoming[0] === track.id"
                                @click="moveUpcoming(track.id, -1)"
                                ><ArrowUp class="size-4"
                            /></Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                :aria-label="'Move down: ' + track.name"
                                :disabled="upcoming.at(-1) === track.id"
                                @click="moveUpcoming(track.id, 1)"
                                ><ArrowDown class="size-4"
                            /></Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                :aria-label="'Remove from queue: ' + track.name"
                                @click="removeQueued(track.id)"
                                ><X class="size-4"
                            /></Button>
                        </template>
                    </li>
                </ol>
            </div>
        </div>
        <form
            v-if="locked"
            class="flex flex-wrap items-center gap-2"
            @submit.prevent="unlock"
        >
            <input
                v-model="password"
                type="password"
                autocomplete="off"
                required
                :aria-label="recovery ? 'Recovery key' : 'Vault password'"
                :placeholder="recovery ? 'Recovery key' : 'Vault password'"
                class="rounded-md border px-3 py-2 text-sm"
            />
            <Button size="sm" :disabled="busy"
                ><Play class="size-4" /> Unlock and play</Button
            >
            <label class="flex items-center gap-2 text-sm"
                ><input v-model="recovery" type="checkbox" /> Use recovery
                key</label
            >
        </form>
        <p v-if="error" role="alert" class="text-destructive text-sm">
            {{ error }}
        </p>
        <MediaControls
            v-if="source"
            ref="controls"
            :media="media"
            :video="mime.startsWith('video/')"
            :compact="docked"
        >
            <component
                v-for="item in playbackSources"
                :is="
                    item.active && mime.startsWith('video/') ? 'video' : 'audio'
                "
                :key="`${item.id}:${item.url}`"
                :ref="
                    (element: unknown) =>
                        setPlaybackElement(element, item.active)
                "
                :src="item.url"
                :preload="item.active ? 'metadata' : 'auto'"
                class="max-h-[45vh] w-full rounded-lg"
                playsinline
                @playing="recordPlaying"
                @loadedmetadata="item.active && restorePlaybackPosition()"
                @timeupdate="startCrossfade"
                @pause="item.active && !media?.ended && interruptCrossfade()"
                @seeking="item.active && interruptCrossfade()"
                @ended="item.active && advance(true)"
                @error="item.active && fallbackToPreparedDownload(item.url)"
            />
        </MediaControls>
    </section>
</template>
