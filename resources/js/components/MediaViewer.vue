<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import MediaControls from '@/components/MediaControls.vue';
import { useLocalization } from '@/composables/useLocalization';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

const props = defineProps<{
    open: boolean;
    file: { id: number; name: string; mime_type: string } | null;
    url: string;
    stream?: ReadableStream<Uint8Array> | null;
    error: string;
}>();
const emit = defineEmits<{
    'update:open': [boolean];
    retry: [];
    download: [];
}>();
const { t } = useLocalization();
const media = ref<HTMLMediaElement | null>(null);
const playbackError = ref('');
let streamReader: ReadableStreamDefaultReader<Uint8Array> | undefined;
let streamUrl = '';
let streamGeneration = 0;
let releasePendingWait: (() => void) | undefined;
const kind = computed(() => {
    const mime = (props.file?.mime_type ?? '').split(';', 1)[0];
    if (
        [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/avif',
        ].includes(mime)
    )
        return 'image';
    if (['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/webm'].includes(mime))
        return 'audio';
    if (['video/mp4', 'video/webm'].includes(mime)) return 'video';
    if (['application/pdf', 'text/plain'].includes(mime)) return 'document';
    return null;
});
function release(): void {
    streamGeneration++;
    releasePendingWait?.();
    releasePendingWait = undefined;
    void streamReader?.cancel().catch(() => {});
    streamReader = undefined;
    if (streamUrl) URL.revokeObjectURL(streamUrl);
    streamUrl = '';
    if (!media.value) return;
    media.value.pause();
    media.value.removeAttribute('src');
    media.value.load();
}
async function playStream(
    stream: ReadableStream<Uint8Array>,
    mime: string,
    generation: number,
): Promise<void> {
    const sourceMime = mime;
    if (!window.MediaSource) {
        throw new Error(
            t('This browser cannot stream protected media. Use Download.'),
        );
    }
    if (!MediaSource.isTypeSupported(sourceMime)) {
        throw new Error(
            t(
                'This protected media format cannot stream in this browser. Use Download.',
            ),
        );
    }
    if (!media.value) {
        throw new Error(t('The media viewer is not ready. Close and retry.'));
    }
    const source = new MediaSource();
    streamUrl = URL.createObjectURL(source);
    media.value.src = streamUrl;
    const opened = await new Promise<boolean>((resolve, reject) => {
        const cleanup = (): void => {
            source.removeEventListener('sourceopen', onOpen);
            source.removeEventListener('sourceclose', onClose);
            source.removeEventListener('error', onError);
            releasePendingWait = undefined;
        };
        const onOpen = (): void => {
            cleanup();
            resolve(true);
        };
        const onClose = (): void => {
            cleanup();
            resolve(false);
        };
        const onError = (): void => {
            cleanup();
            reject(new Error(t('Streaming playback failed. Use Download.')));
        };
        releasePendingWait = onClose;
        source.addEventListener('sourceopen', onOpen, { once: true });
        source.addEventListener('sourceclose', onClose, { once: true });
        source.addEventListener('error', onError, { once: true });
    });
    if (!opened) return;
    if (generation !== streamGeneration || !media.value) return;
    const buffer = source.addSourceBuffer(sourceMime);
    const reader = stream.getReader();
    streamReader = reader;
    const waitForBuffer = (): Promise<void> =>
        new Promise((resolve, reject) => {
            buffer.addEventListener('updateend', () => resolve(), {
                once: true,
            });
            buffer.addEventListener(
                'error',
                () => reject(new Error(t('Media could not be decoded.'))),
                { once: true },
            );
        });
    const waitForPlayback = (): Promise<void> =>
        new Promise((resolve) => {
            const element = media.value;
            if (!element) {
                resolve();
                return;
            }
            const complete = (): void => {
                element.removeEventListener('timeupdate', complete);
                element.removeEventListener('emptied', complete);
                releasePendingWait = undefined;
                resolve();
            };
            releasePendingWait = complete;
            element.addEventListener('timeupdate', complete, { once: true });
            element.addEventListener('emptied', complete, { once: true });
        });
    try {
        while (generation === streamGeneration) {
            const { done, value } = await reader.read();
            if (done) break;
            if (generation !== streamGeneration) break;
            if (buffer.updating) await waitForBuffer();
            const mediaBytes = new Uint8Array(value.byteLength);
            mediaBytes.set(value);
            buffer.appendBuffer(mediaBytes.buffer);
            await waitForBuffer();
            const currentTime = media.value?.currentTime ?? 0;
            if (buffer.buffered.length) {
                const start = buffer.buffered.start(0);
                const end = buffer.buffered.end(buffer.buffered.length - 1);
                if (start < currentTime - 30 && !buffer.updating) {
                    buffer.remove(start, currentTime - 30);
                    await waitForBuffer();
                }
                if (end - currentTime > 30) await waitForPlayback();
            }
        }
        if (generation === streamGeneration && source.readyState === 'open')
            source.endOfStream();
    } finally {
        reader.releaseLock();
        if (streamReader === reader) streamReader = undefined;
    }
}
watch(
    () => [props.open, props.file?.id, props.url, props.stream],
    async () => {
        release();
        playbackError.value = '';
        if (props.open && props.stream && props.file) {
            const generation = streamGeneration;
            try {
                await nextTick();
                if (generation !== streamGeneration) return;
                await playStream(
                    props.stream,
                    props.file.mime_type,
                    generation,
                );
            } catch (error) {
                if (generation === streamGeneration)
                    playbackError.value =
                        error instanceof Error &&
                        error.name === 'OperationError'
                            ? t(
                                  'Protected media could not be authenticated. The vault key may be wrong or the encrypted file may be damaged.',
                              )
                            : error instanceof Error
                              ? error.message
                              : t('Playback failed. Use Download.');
            }
        }
    },
    { flush: 'sync' },
);
onBeforeUnmount(release);
</script>

<template>
    <Sheet :open="open" @update:open="emit('update:open', $event)">
        <SheetContent
            class="w-full sm:w-[min(80vw,48rem)] sm:max-w-none"
            data-test="media-viewer"
        >
            <SheetHeader class="pe-12">
                <SheetTitle class="break-words">{{ file?.name }}</SheetTitle>
                <SheetDescription>{{
                    t('Preview file contents and details.')
                }}</SheetDescription>
            </SheetHeader>
            <div class="flex min-h-0 flex-1 flex-col gap-6 overflow-auto px-4">
                <div
                    class="bg-muted/60 flex min-h-64 flex-col justify-center gap-4 rounded-2xl border p-4"
                >
                    <p v-if="error || playbackError" role="alert">
                        {{ error || playbackError }}
                    </p>
                    <p v-else-if="!kind" role="alert">
                        {{
                            t(
                                'Preview is unavailable for this format. Use Download.',
                            )
                        }}
                    </p>
                    <p
                        v-else-if="!url && !stream"
                        class="animate-pulse"
                        role="status"
                    >
                        {{ t('Preparing preview…') }}
                    </p>
                    <template v-else-if="open && file">
                        <img
                            v-if="kind === 'image'"
                            :key="url"
                            :src="url || undefined"
                            :alt="file.name"
                            class="max-h-[50vh] w-full object-contain"
                            @error="
                                playbackError = t(
                                    'Preview is unavailable or the saved copy may be corrupt. Refresh it online or use Download.',
                                )
                            "
                        />
                        <MediaControls
                            v-else-if="kind === 'video' || kind === 'audio'"
                            :media="media"
                            :video="kind === 'video'"
                        >
                            <component
                                :is="kind"
                                :key="url"
                                ref="media"
                                :src="url || undefined"
                                playsinline
                                preload="metadata"
                                class="max-h-[50vh]"
                                @error="
                                    playbackError = t(
                                        'Playback is unavailable or the saved copy may be corrupt. Refresh it online or download.',
                                    )
                                "
                            />
                        </MediaControls>
                        <iframe
                            v-else-if="kind === 'document'"
                            :key="url"
                            :src="url"
                            sandbox=""
                            :title="file.name"
                            class="h-full min-h-64 w-full border"
                        />
                    </template>
                </div>
                <slot />
            </div>
            <div v-if="file" class="flex shrink-0 gap-2 p-4">
                <Button variant="outline" @click="emit('retry')">{{
                    t('Prepare again')
                }}</Button>
                <Button @click="emit('download')">{{ t('Download') }}</Button>
            </div>
        </SheetContent>
    </Sheet>
</template>
