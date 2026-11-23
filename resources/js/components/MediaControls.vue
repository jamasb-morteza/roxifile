<script setup lang="ts">
import {
    Maximize,
    Minimize,
    Pause,
    Play,
    SlidersHorizontal,
    Volume2,
    VolumeX,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import {
    canProcessMedia,
    createMediaAudioGraph,
    equalizerFrequencies,
    equalizerPresets,
} from '@/lib/mediaEqualizer';
import { normalizeVolume } from '@/lib/mediaShortcuts';
import { useLocalization } from '@/composables/useLocalization';
import { waveformPeaks } from '@/lib/mediaWaveform';

const { t } = useLocalization();
const props = defineProps<{
    media: HTMLMediaElement | null;
    video: boolean;
    compact?: boolean;
}>();
const frame = ref<HTMLElement | null>(null);
const canvas = ref<HTMLCanvasElement | null>(null);
const elapsed = ref(0);
const duration = ref(0);
const playing = ref(false);
const muted = ref(false);
const volume = ref(1);
const volumePreference = 'roxifile.media-volume';
const fullscreen = ref(false);
const error = ref('');
const showEqualizer = ref(false);
const equalizerReady = ref(false);
const bypass = ref(false);
const preset = ref<keyof typeof equalizerPresets | 'Custom'>('Flat');
const gains = ref<number[]>([...equalizerPresets.Flat]);
const progress = computed(() =>
    duration.value ? (elapsed.value / duration.value) * 100 : 0,
);
let context: AudioContext | undefined;
let graph: ReturnType<typeof createMediaAudioGraph>;
let animation = 0;

function setPreset(event: Event): void {
    const name = (event.target as HTMLSelectElement)
        .value as typeof preset.value;
    if (name === 'Custom') return;
    preset.value = name;
    gains.value = [...equalizerPresets[name]];
}

function setBand(index: number, event: Event): void {
    preset.value = 'Custom';
    gains.value[index] = (event.target as HTMLInputElement).valueAsNumber;
}

watch([gains, bypass], () => graph?.setEqualizer(gains.value, bypass.value), {
    deep: true,
});

function clock(seconds: number): string {
    const whole = Math.floor(seconds);
    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}

function draw(peaks: number[]): void {
    const surface = canvas.value;
    const brush = surface?.getContext('2d');
    if (!surface || !brush) return;
    const { width, height } = surface;
    brush.clearRect(0, 0, width, height);
    const spacing = width / peaks.length;
    peaks.forEach((peak, index) => {
        const amplitude = Math.max(3, Math.min(1, peak * 2.4) * height * 0.85);
        brush.fillStyle =
            (index / peaks.length) * 100 <= progress.value
                ? '#6ee7b7'
                : '#6b8b82';
        brush.fillRect(
            index * spacing,
            (height - amplitude) / 2,
            spacing * 0.62,
            amplitude,
        );
    });
}

async function visualise(element: HTMLMediaElement): Promise<void> {
    if (
        props.video ||
        !globalThis.AudioContext ||
        navigator.userActivation?.hasBeenActive === false ||
        !canProcessMedia(element, location.origin)
    )
        return;
    try {
        const audio = (context ??= new AudioContext());
        if (audio.state !== 'running') await audio.resume();
        if (props.media !== element || element.paused) return;
        graph ??= createMediaAudioGraph(audio, element, location.origin);
        equalizerReady.value = !!graph;
        if (!graph) return;

        graph.setEqualizer(gains.value, bypass.value);
        cancelAnimationFrame(animation);
        const { analyser } = graph;
        const signal = new Float32Array(analyser.fftSize);
        function paint(): void {
            analyser.getFloatTimeDomainData(signal);
            draw(waveformPeaks(signal, 80));
            animation = requestAnimationFrame(paint);
        }
        paint();
    } catch {
        draw(Array<number>(80).fill(0));
    }
}

watch(
    () => props.media,
    (element, _, onCleanup) => {
        error.value = '';
        function sync(): void {
            elapsed.value = element?.currentTime ?? 0;
            duration.value = Number.isFinite(element?.duration)
                ? element!.duration
                : 0;
            playing.value = !!element && !element.paused && !element.ended;
            muted.value = element?.muted ?? false;
            volume.value = element?.volume ?? 1;
        }
        sync();
        draw(Array<number>(80).fill(0));
        if (!element) return;
        try {
            element.volume = normalizeVolume(
                localStorage.getItem(volumePreference),
            );
        } catch {
            // Playback is available when browser preference storage is blocked.
        }
        sync();
        const play = () => {
            sync();
            void visualise(element);
        };
        const pause = () => {
            sync();
            cancelAnimationFrame(animation);
        };
        const events = [
            'timeupdate',
            'loadedmetadata',
            'durationchange',
            'emptied',
            'volumechange',
        ] as const;
        for (const name of events) element.addEventListener(name, sync);
        element.addEventListener('play', play);
        element.addEventListener('pause', pause);
        element.addEventListener('ended', pause);
        if (playing.value) void visualise(element);
        onCleanup(() => {
            for (const name of events) element.removeEventListener(name, sync);
            element.removeEventListener('play', play);
            element.removeEventListener('pause', pause);
            element.removeEventListener('ended', pause);
            cancelAnimationFrame(animation);
            graph?.disconnect();
            graph = undefined;
            equalizerReady.value = false;
        });
    },
    { flush: 'post' },
);

async function togglePlay(): Promise<void> {
    const element = props.media;
    if (!element) return;
    error.value = '';
    if (!element.paused) {
        element.pause();
        return;
    }
    try {
        await element.play();
    } catch {
        error.value = t('Playback could not start in this browser.');
    }
}

function seek(event: Event): void {
    if (props.media)
        props.media.currentTime = Number(
            (event.target as HTMLInputElement).value,
        );
}

function toggleMute(): void {
    if (props.media) props.media.muted = !props.media.muted;
}

function setVolume(value: number): void {
    if (!props.media) return;
    props.media.volume = normalizeVolume(value);
    try {
        localStorage.setItem(volumePreference, String(props.media.volume));
    } catch {
        // The current volume still applies for this player.
    }
}

defineExpose({ togglePlay, toggleMute, setVolume });

function updateFullscreen(): void {
    fullscreen.value = document.fullscreenElement === frame.value;
}

async function toggleFullscreen(): Promise<void> {
    error.value = '';
    try {
        if (fullscreen.value) await document.exitFullscreen();
        else if (frame.value?.requestFullscreen)
            await frame.value.requestFullscreen();
        else {
            const video = props.media as
                | (HTMLVideoElement & { webkitEnterFullscreen?: () => void })
                | null;
            if (!video?.webkitEnterFullscreen) throw new Error();
            video.webkitEnterFullscreen();
        }
        updateFullscreen();
    } catch {
        error.value = t('Fullscreen is unavailable in this browser.');
    }
}

watch(frame, (element, _, onCleanup) => {
    if (!element) return;
    document.addEventListener('fullscreenchange', updateFullscreen);
    onCleanup(() =>
        document.removeEventListener('fullscreenchange', updateFullscreen),
    );
});
onBeforeUnmount(() => {
    cancelAnimationFrame(animation);
    void context?.close().catch(() => {});
});
</script>

<template>
    <div
        ref="frame"
        class="fullscreen:flex fullscreen:flex-col fullscreen:justify-center fullscreen:rounded-none fullscreen:overflow-auto overflow-hidden rounded-2xl bg-[#101b19] text-white"
    >
        <div
            :class="
                video
                    ? 'fullscreen:[&>video]:max-h-[85vh] bg-black [&>video]:mx-auto [&>video]:w-full'
                    : '[&>audio]:hidden'
            "
        >
            <slot />
        </div>
        <div
            class="space-y-3 bg-linear-to-br from-emerald-950 to-[#101b19] p-4 sm:p-5"
        >
            <canvas
                v-if="!video"
                ref="canvas"
                width="640"
                :height="fullscreen ? 240 : 96"
                class="w-full"
                :class="
                    fullscreen
                        ? 'h-[clamp(5rem,28vh,20rem)]'
                        : compact
                          ? 'h-8'
                          : 'h-20'
                "
                :aria-label="t('Live audio waveform')"
                role="img"
            />
            <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                <button
                    type="button"
                    class="grid size-11 shrink-0 place-items-center rounded-full bg-emerald-300 text-emerald-950 hover:bg-emerald-200 disabled:opacity-50"
                    :aria-label="playing ? t('Pause') : t('Play')"
                    :disabled="!media"
                    @click="togglePlay"
                >
                    <Pause v-if="playing" class="size-5" /><Play
                        v-else
                        class="size-5"
                    />
                </button>
                <div class="min-w-16 flex-1">
                    <input
                        class="block w-full cursor-pointer accent-emerald-300"
                        type="range"
                        min="0"
                        :max="duration || 1"
                        step="any"
                        :value="elapsed"
                        :disabled="!duration"
                        :aria-label="t('Playback position')"
                        @input="seek"
                    />
                    <div
                        class="flex justify-between text-xs text-emerald-100/80 tabular-nums"
                    >
                        <span>{{ clock(elapsed) }}</span
                        ><span>{{ clock(duration) }}</span>
                    </div>
                </div>
                <button
                    type="button"
                    class="grid size-9 shrink-0 place-items-center rounded-lg hover:bg-white/15"
                    :aria-label="muted ? t('Unmute') : t('Mute')"
                    @click="toggleMute"
                >
                    <VolumeX v-if="muted" class="size-4" /><Volume2
                        v-else
                        class="size-4"
                    />
                </button>
                <input
                    type="range"
                    min="0"
                    max="1"
                    step="0.01"
                    :value="volume"
                    :aria-label="t('Volume')"
                    class="w-14 shrink-0 accent-emerald-300 sm:w-24"
                    @input="
                        setVolume(
                            Number(($event.target as HTMLInputElement).value),
                        )
                    "
                />
                <button
                    v-if="!video && !fullscreen"
                    type="button"
                    class="grid size-9 shrink-0 place-items-center rounded-lg hover:bg-white/15"
                    :aria-label="t('Equalizer')"
                    :aria-expanded="showEqualizer || fullscreen"
                    @click="showEqualizer = !showEqualizer"
                >
                    <SlidersHorizontal class="size-4" />
                </button>
                <button
                    type="button"
                    class="grid size-9 shrink-0 place-items-center rounded-lg hover:bg-white/15"
                    :aria-label="
                        fullscreen ? t('Exit fullscreen') : t('Fullscreen')
                    "
                    @click="toggleFullscreen"
                >
                    <Minimize v-if="fullscreen" class="size-4" /><Maximize
                        v-else
                        class="size-4"
                    />
                </button>
            </div>
            <fieldset
                v-if="!video && (showEqualizer || fullscreen)"
                class="space-y-3 rounded-lg border border-white/20 px-3 pb-3"
            >
                <legend class="px-1 text-sm font-medium">
                    {{ t('Equalizer') }}
                </legend>
                <p v-if="!equalizerReady" class="text-sm text-emerald-100/80">
                    {{
                        t(
                            'Equalizer controls are available during supported audio playback.',
                        )
                    }}
                </p>
                <template v-else>
                    <div
                        class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm"
                    >
                        <label class="flex items-center gap-2">
                            {{ t('Preset') }}
                            <select
                                :value="preset"
                                class="rounded-md border border-white/20 bg-emerald-950 px-2 py-1"
                                @change="setPreset"
                            >
                                <option
                                    v-for="(_, name) in equalizerPresets"
                                    :key="name"
                                >
                                    {{ name }}
                                </option>
                                <option value="Custom" disabled>
                                    {{ t('Custom') }}
                                </option>
                            </select>
                        </label>
                        <label class="flex items-center gap-2">
                            <input
                                v-model="bypass"
                                type="checkbox"
                                class="accent-emerald-300"
                            />
                            {{ t('Bypass') }}
                        </label>
                    </div>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                        <label
                            v-for="(frequency, index) in equalizerFrequencies"
                            :key="frequency"
                            class="flex min-w-0 flex-col gap-1 text-xs"
                        >
                            <span>{{
                                frequency < 1000
                                    ? frequency + ' Hz'
                                    : frequency / 1000 + ' kHz'
                            }}</span>
                            <input
                                type="range"
                                min="-12"
                                max="12"
                                :value="gains[index]"
                                :disabled="bypass"
                                class="w-full accent-emerald-300 disabled:opacity-50"
                                @input="setBand(index, $event)"
                            />
                            <span class="text-emerald-100/80 tabular-nums"
                                >{{ gains[index] }} dB</span
                            >
                        </label>
                    </div>
                </template>
            </fieldset>
            <p v-if="error" role="alert" class="text-sm text-rose-200">
                {{ error }}
            </p>
        </div>
    </div>
</template>
