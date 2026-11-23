<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useLocalization } from '@/composables/useLocalization';
import { Button } from '@/components/ui/button';
import MediaViewer from '@/components/MediaViewer.vue';
import type { ProtectedMetadata } from '@/lib/contentsEncryption';
import {
    decryptBrowserCopy,
    decryptBrowserCopyStream,
    unlockBrowserCopy,
} from '@/lib/encryptedBrowserCopy';
import {
    browserAccount,
    browserLogoutEpoch,
    onBrowserCopiesChanged,
    readBrowserCopy,
    type BrowserCopy,
} from '@/lib/browserDownloads';

const props = defineProps<{ account: number; copy: BrowserCopy }>();
const { t } = useLocalization();
const copy = () => props.copy;
const credential = ref('');
const useRecovery = ref(false);
const key = ref<CryptoKey | null>(null);
const metadata = ref<ProtectedMetadata | null>(null);
const error = ref('');
const previewError = ref('');
const previewUrl = ref('');
const previewOpen = ref(false);
const previewBusy = ref(false);
let releasePreview: (() => void) | undefined;
let logoutEpoch = browserLogoutEpoch(props.account);
let unsubscribe: (() => void) | undefined;
let unlockGeneration = 0;
let activeDownload: AbortController | undefined;

function lock(): void {
    unlockGeneration += 1;
    activeDownload?.abort();
    activeDownload = undefined;
    key.value = null;
    metadata.value = null;
    credential.value = '';
    useRecovery.value = false;
    closePreview();
}

function closePreview(): void {
    previewOpen.value = false;
    releasePreview?.();
    releasePreview = undefined;
    previewUrl.value = '';
    previewBusy.value = false;
}

function lockOnPageHide(): void {
    lock();
}

watch(
    () => [props.account, props.copy.id, props.copy.saved_at],
    () => {
        lock();
        error.value = '';
    },
);

onBeforeUnmount(() => {
    unsubscribe?.();
    window.removeEventListener('pagehide', lockOnPageHide);
    lock();
});

onMounted(() => window.addEventListener('pagehide', lockOnPageHide));

unsubscribe = onBrowserCopiesChanged(() => {
    const currentEpoch = browserLogoutEpoch(props.account);
    if (browserAccount() !== props.account || currentEpoch !== logoutEpoch) {
        logoutEpoch = currentEpoch;
        lock();
    }
});

async function unlock(): Promise<void> {
    const generation = ++unlockGeneration;
    const account = props.account;
    const savedCopy = copy();
    error.value = '';
    try {
        const unlocked = await unlockBrowserCopy(
            savedCopy,
            account,
            credential.value,
            useRecovery.value,
        );
        if (
            generation !== unlockGeneration ||
            props.account !== account ||
            props.copy.id !== savedCopy.id ||
            props.copy.saved_at !== savedCopy.saved_at ||
            browserAccount() !== account
        ) {
            return;
        }
        key.value = unlocked.key;
        metadata.value = unlocked.metadata;
    } catch (cause) {
        if (generation !== unlockGeneration) return;
        error.value =
            cause instanceof Error
                ? cause.message
                : t('The saved file could not be unlocked.');
    } finally {
        credential.value = '';
    }
}

function displayName(): string {
    return metadata.value?.name ?? copy().name;
}

function displayMime(): string {
    return metadata.value?.mime ?? copy().mime_type;
}

function isSupportedMedia(): boolean {
    return [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
        'audio/mpeg',
        'audio/ogg',
        'audio/wav',
        'audio/webm',
        'video/mp4',
        'video/webm',
    ].includes(displayMime());
}

function downloadBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function assertDownloadIsCurrent(
    controller: AbortController,
    generation: number,
    account: number,
    savedCopy: BrowserCopy,
    downloadKey: CryptoKey,
): void {
    controller.signal.throwIfAborted();
    if (
        generation !== unlockGeneration ||
        props.account !== account ||
        props.copy.id !== savedCopy.id ||
        props.copy.saved_at !== savedCopy.saved_at ||
        key.value !== downloadKey ||
        browserAccount() !== account
    ) {
        controller.abort();
        controller.signal.throwIfAborted();
    }
}

async function download(): Promise<void> {
    const downloadKey = key.value;
    const savedCopy = copy();
    const account = props.account;
    if (!downloadKey || !savedCopy.connection_id) return;
    activeDownload?.abort();
    const controller = new AbortController();
    activeDownload = controller;
    const generation = unlockGeneration;
    error.value = '';
    let destination: FileSystemWritableFileStream | undefined;
    try {
        const ciphertext = await readBrowserCopy(account, savedCopy.id);
        assertDownloadIsCurrent(
            controller,
            generation,
            account,
            savedCopy,
            downloadKey,
        );
        if (ciphertext.size > 32 * 1024 * 1024) {
            const saveFilePicker = (
                window as Window & {
                    showSaveFilePicker?: (options: {
                        suggestedName: string;
                    }) => Promise<FileSystemFileHandle>;
                }
            ).showSaveFilePicker;
            if (!saveFilePicker) {
                throw new Error(
                    t('Large encrypted downloads need the browser file picker. Use a browser that supports saving files.'),
                );
            }
            const handle = await saveFilePicker.call(window, {
                suggestedName: displayName(),
            });
            assertDownloadIsCurrent(
                controller,
                generation,
                account,
                savedCopy,
                downloadKey,
            );
            destination = await handle.createWritable();
        }
        assertDownloadIsCurrent(
            controller,
            generation,
            account,
            savedCopy,
            downloadKey,
        );
        const plaintext = await decryptBrowserCopy(
            savedCopy,
            account,
            downloadKey,
            ciphertext,
            controller.signal,
            destination,
        );
        assertDownloadIsCurrent(
            controller,
            generation,
            account,
            savedCopy,
            downloadKey,
        );
        if (!destination) downloadBlob(plaintext.file, displayName());
        await plaintext.dispose();
    } catch (cause) {
        await destination?.abort().catch(() => {});
        if (!controller.signal.aborted) {
            error.value =
                cause instanceof Error
                    ? cause.message
                    : 'The saved file could not be decrypted.';
        }
    } finally {
        if (activeDownload === controller) activeDownload = undefined;
    }
}

async function pumpMedia(
    reader: ReadableStreamDefaultReader<Uint8Array<ArrayBuffer>>,
    mediaSource: MediaSource,
    sourceBuffer: SourceBuffer,
    signal: AbortSignal,
): Promise<void> {
    const append = (bytes: Uint8Array<ArrayBuffer>): Promise<void> =>
        new Promise((resolve, reject) => {
            const finish = (failure?: Error): void => {
                sourceBuffer.removeEventListener('updateend', onUpdateEnd);
                sourceBuffer.removeEventListener('error', onError);
                signal.removeEventListener('abort', onAbort);
                bytes.fill(0);
                if (failure) reject(failure);
                else resolve();
            };
            const onUpdateEnd = (): void => finish();
            const onError = (): void =>
                finish(
                    new Error('The browser could not play this saved media.'),
                );
            const onAbort = (): void =>
                finish(new DOMException('Aborted', 'AbortError'));
            sourceBuffer.addEventListener('updateend', onUpdateEnd, {
                once: true,
            });
            sourceBuffer.addEventListener('error', onError, { once: true });
            signal.addEventListener('abort', onAbort, { once: true });
            try {
                sourceBuffer.appendBuffer(bytes);
            } catch (cause) {
                finish(
                    cause instanceof Error
                        ? cause
                        : new Error(
                    t('The browser could not play this saved media.'),
                          ),
                );
            }
        });

    while (true) {
        signal.throwIfAborted();
        const { done, value } = await reader.read();
        if (done) break;
        await append(value);
    }
    if (mediaSource.readyState === 'open') mediaSource.endOfStream();
}

async function preview(): Promise<void> {
    if (!key.value || !copy().connection_id || !isSupportedMedia()) return;
    closePreview();
    previewError.value = '';
    previewOpen.value = true;
    previewBusy.value = true;
    const abort = new AbortController();
    releasePreview = () => abort.abort();
    let ciphertext: File | undefined;
    try {
        ciphertext = await readBrowserCopy(props.account, copy().id);
        if (ciphertext.size > 32 * 1024 * 1024) {
            const mime = displayMime();
            if (
                typeof MediaSource === 'undefined' ||
                !['audio/webm', 'video/webm', 'video/mp4'].includes(mime) ||
                !MediaSource.isTypeSupported(mime)
            ) {
                throw new Error(
                    t('This browser cannot stream the saved encrypted media. Use Download.'),
                );
            }
            const stream = await decryptBrowserCopyStream(
                copy(),
                props.account,
                key.value,
                ciphertext,
                abort.signal,
            );
            const reader = stream.getReader();
            const mediaSource = new MediaSource();
            const url = URL.createObjectURL(mediaSource);
            const onClose = (): void => abort.abort();
            mediaSource.addEventListener('sourceclose', onClose, {
                once: true,
            });
            releasePreview = () => {
                abort.abort();
                void reader.cancel().catch(() => {});
                URL.revokeObjectURL(url);
                mediaSource.removeEventListener('sourceclose', onClose);
            };
            previewUrl.value = url;
            await new Promise<void>((resolve, reject) => {
                const onOpen = (): void => resolve();
                const onAbort = (): void =>
                    reject(new DOMException('Aborted', 'AbortError'));
                mediaSource.addEventListener('sourceopen', onOpen, {
                    once: true,
                });
                abort.signal.addEventListener('abort', onAbort, { once: true });
            });
            const sourceBuffer = mediaSource.addSourceBuffer(mime);
            void pumpMedia(
                reader,
                mediaSource,
                sourceBuffer,
                abort.signal,
            ).catch((cause: unknown) => {
                if (!abort.signal.aborted) {
                    previewError.value =
                        cause instanceof Error
                            ? cause.message
                            : t('Saved media could not be authenticated.');
                }
            });
        } else {
            const plaintext = await decryptBrowserCopy(
                copy(),
                props.account,
                key.value,
                ciphertext,
                abort.signal,
            );
            const url = URL.createObjectURL(
                new Blob([plaintext.file], { type: displayMime() }),
            );
            releasePreview = () => {
                abort.abort();
                URL.revokeObjectURL(url);
                void plaintext.dispose();
            };
            previewUrl.value = url;
        }
    } catch (cause) {
        if (!abort.signal.aborted) {
            previewError.value =
                cause instanceof Error
                    ? cause.message
                    : t('Saved media could not be authenticated.');
        }
        closePreview();
    } finally {
        previewBusy.value = false;
    }
}
</script>

<template>
    <div class="space-y-2">
        <p v-if="!key" class="text-muted-foreground text-sm">
            {{ t('Encrypted copy · Unlock this vault to open it.') }}
        </p>
        <template v-if="!key">
            <label class="flex items-center gap-2 text-sm">
                <input v-model="useRecovery" type="checkbox" /> {{ t('Use recovery key') }}
            </label>
            <input
                v-model="credential"
                type="password"
                autocomplete="off"
                :aria-label="useRecovery ? t('Recovery key') : t('Vault password')"
                :placeholder="useRecovery ? t('Recovery key') : t('Vault password')"
                class="rounded border px-2 py-1 text-sm"
            />
            <Button size="sm" @click="unlock">{{ t('Unlock saved copy') }}</Button>
        </template>
        <template v-else>
            <p class="text-muted-foreground text-sm">
                {{ t('Unlocked in memory') }} · <span class="technical-ltr">{{ displayMime() }}</span>
            </p>
            <Button v-if="isSupportedMedia()" size="sm" @click="preview"
                >{{ t('Open media') }}</Button
            >
            <Button size="sm" variant="outline" @click="download"
                >{{ t('Download decrypted file') }}</Button
            >
            <Button size="sm" variant="ghost" @click="lock">{{ t('Lock') }}</Button>
        </template>
        <p class="font-medium break-words">{{ displayName() }}</p>
        <p v-if="metadata?.description" class="text-sm">
            {{ metadata.description }}
        </p>
        <p v-if="metadata?.tags.length" class="text-sm">
            {{ metadata.tags.join(', ') }}
        </p>
        <p v-if="error" role="alert" class="text-sm">{{ error }}</p>
        <p v-if="previewBusy" role="status" class="text-sm">
            {{ t('Preparing encrypted media…') }}
        </p>
        <MediaViewer
            v-model:open="previewOpen"
            :file="{
                id: copy().id,
                name: displayName(),
                mime_type: displayMime(),
            }"
            :url="previewUrl"
            :error="previewError"
            @update:open="!$event && closePreview()"
            @retry="preview"
            @download="download"
        />
    </div>
</template>
