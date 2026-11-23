<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { useLocalization } from '@/composables/useLocalization';
import { Button } from '@/components/ui/button';
import {
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    browserOriginal,
    preparedDownloadUrl,
} from '@/lib/browserDownloadTransfer';
import { browserAccount, browserLogoutEpoch } from '@/lib/browserDownloads';
import { stageDownload } from '@/lib/contentsEncryption';
import {
    decryptBrowserCopy,
    unlockBrowserCopy,
} from '@/lib/encryptedBrowserCopy';
import {
    exportMediaTracks,
    type MediaDownloadProgress,
} from '@/lib/mediaDownloads';

const props = defineProps<{
    account: number;
    ids: number[];
    encrypted: boolean;
    names: ReadonlyMap<number, string>;
}>();
const { t } = useLocalization();

const secret = ref('');
const recovery = ref(false);
const running = ref(false);
const progress = ref<MediaDownloadProgress>();
const result = ref<Awaited<ReturnType<typeof exportMediaTracks>>>();
const request = new AbortController();
const epoch = browserLogoutEpoch(props.account);
onBeforeUnmount(() => request.abort());

function checkSession(signal: AbortSignal): void {
    if (
        browserAccount() !== props.account ||
        browserLogoutEpoch(props.account) !== epoch
    ) {
        request.abort();
    }
    signal.throwIfAborted();
}

function download(url: string, filename: string): void {
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
}

async function start(): Promise<void> {
    if (running.value || result.value) return;
    running.value = true;
    const password = secret.value;
    secret.value = '';
    try {
        result.value = await exportMediaTracks(
            props.ids,
            request.signal,
            async (id, signal) => {
                checkSession(signal);
                const snapshot = await browserOriginal(id, signal);
                if (!snapshot) throw new Error(t('The original is unavailable.'));
                if (!snapshot.encryption_header) {
                    const url = await preparedDownloadUrl(id, signal);
                    const available = await fetch(url, {
                        method: 'HEAD',
                        signal,
                    });
                    if (
                        !available.ok ||
                        !available.headers.has('Content-Disposition')
                    ) {
                        throw new Error(
                            t('The prepared download is unavailable. Try again.'),
                        );
                    }
                    checkSession(signal);
                    download(url, snapshot.name);
                    return;
                }
                if (!password)
                    throw new Error(
                        t('Enter the vault credential to download this track.'),
                    );
                const copy = {
                    ...snapshot,
                    account: props.account,
                    path: '',
                    saved_at: '',
                    original_missing: false,
                };
                const { key, metadata } = await unlockBrowserCopy(
                    copy,
                    props.account,
                    password,
                    recovery.value,
                );
                const url = await preparedDownloadUrl(id, signal);
                const filename = metadata?.name ?? snapshot.name;
                let destination: FileSystemWritableFileStream | undefined;
                try {
                    if (snapshot.size > 32 * 1024 * 1024) {
                        const picker = (
                            window as Window & {
                                showSaveFilePicker?: (options: {
                                    suggestedName: string;
                                }) => Promise<FileSystemFileHandle>;
                            }
                        ).showSaveFilePicker;
                        if (!picker) {
                            throw new Error(
                                t('Large protected exports need a browser that supports Save File.'),
                            );
                        }
                        const handle = await picker.call(window, {
                            suggestedName: filename,
                        });
                        checkSession(signal);
                        destination = await handle.createWritable();
                    }
                    checkSession(signal);
                    const ciphertext = await stageDownload(
                        await fetch(url, { signal }),
                        snapshot.size,
                        signal,
                    );
                    try {
                        const clear = await decryptBrowserCopy(
                            copy,
                            props.account,
                            key,
                            ciphertext.file,
                            signal,
                            destination,
                        );
                        try {
                            checkSession(signal);
                            if (!destination) {
                                const objectUrl = URL.createObjectURL(
                                    clear.file,
                                );
                                download(objectUrl, filename);
                                setTimeout(
                                    () => URL.revokeObjectURL(objectUrl),
                                    1000,
                                );
                            }
                        } finally {
                            await clear.dispose();
                        }
                    } finally {
                        await ciphertext.dispose();
                    }
                } catch (cause) {
                    await destination?.abort().catch(() => {});
                    throw cause;
                }
            },
            (update) => (progress.value = update),
        );
    } finally {
        running.value = false;
    }
}
</script>

<template>
    <DialogContent>
        <DialogTitle>{{ t('Export tracks') }}</DialogTitle>
        <DialogDescription
            >{{ t('Download :count tracks as individual files. Your browser may ask to allow multiple downloads.', { count: ids.length }) }}</DialogDescription
        >
        <form v-if="!result" class="space-y-3" @submit.prevent="start">
            <template v-if="encrypted && !running">
                <label class="block space-y-1">
                    <span>{{
                        recovery ? t('Recovery key') : t('Vault password')
                    }}</span>
                    <Input
                        v-model="secret"
                        type="password"
                        autocomplete="off"
                    />
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="recovery" type="checkbox" /> {{ t('Use recovery key') }}
                </label>
            </template>
            <p v-if="running && progress" role="status">
                {{ t('Preparing :position of :count', { position: progress.position, count: progress.count }) }} ·
                {{ names.get(progress.id) ?? `Track ${progress.id}` }}
            </p>
            <Button v-if="!running" type="submit">{{ t('Start downloads') }}</Button>
            <Button
                v-else
                type="button"
                variant="secondary"
                @click="request.abort()"
                >{{ t('Stop downloads') }}</Button
            >
        </form>
        <div v-else class="space-y-2 text-sm">
            <p role="status">
                {{ result.cancelled ? t('Export stopped.') : t('Export finished.') }}
                {{ t(':saved downloads started, :failed failed.', { saved: result.saved.length, failed: result.failures.length }) }}
            </p>
            <ul class="max-h-48 list-disc overflow-y-auto pl-5">
                <li v-for="failure in result.failures" :key="failure.id">
                    {{ names.get(failure.id) ?? `Track ${failure.id}` }}:
                    {{ failure.message }}
                </li>
            </ul>
        </div>
    </DialogContent>
</template>
