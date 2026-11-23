<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useLocalization } from '@/composables/useLocalization';
import { formatDate, formatFileSize, formatNumber } from '@/lib/localization.js';
import { Button } from '@/components/ui/button';
import OfflineEncryptedCopy from '@/components/OfflineEncryptedCopy.vue';
import MediaViewer from '@/components/MediaViewer.vue';
import {
    browserOriginal,
    downloadToBrowser,
} from '@/lib/browserDownloadTransfer';
import {
    browserAccount,
    browserStorageAvailable,
    fileBrowserCopies,
    listBrowserCopies,
    markBrowserOriginalMissing,
    offlineShell,
    onBrowserCopiesChanged,
    readBrowserCopy,
    reconcileBrowserCopies,
    removeBrowserCopy,
    retainBrowserCopies,
    retainsBrowserCopies,
    setBrowserAccount,
    type BrowserCopy,
} from '@/lib/browserDownloads';
const props = withDefaults(
    defineProps<{ account: number; offline?: boolean; fileId?: number }>(),
    { offline: false },
);
const { locale, t } = useLocalization();
const copies = ref<BrowserCopy[]>([]);
const visibleCopies = computed(() =>
    fileBrowserCopies(copies.value, props.fileId),
);
const error = ref('');
const notice = ref('');
const retain = ref(false);
const jobs = ref(
    new Map<
        number,
        { controller: AbortController; bytes: number; total: number }
    >(),
);
const loading = ref(true);
const previewOpen = ref(false);
const previewFile = ref<BrowserCopy | null>(null);
const previewUrl = ref('');
const previewError = ref('');
let previewGeneration = 0;
let unsubscribe: (() => void) | undefined;
async function reload(): Promise<void> {
    if (browserAccount() !== props.account) {
        copies.value = [];
        for (const job of jobs.value.values()) job.controller.abort();
        return;
    }
    try {
        copies.value = await listBrowserCopies(props.account);
    } catch (cause) {
        error.value = message(cause);
    }
}
function message(cause: unknown): string {
    if (cause instanceof DOMException && cause.name === 'QuotaExceededError')
        return t('Browser storage is full. Remove browser copies or use a regular download. The previous snapshot is preserved.');
    return cause instanceof Error
        ? cause.message
        : t('Browser storage is unavailable. Use a regular download.');
}
async function save(id: number): Promise<void> {
    if (jobs.value.has(id)) return;
    const controller = new AbortController();
    jobs.value.set(id, { controller, bytes: 0, total: 0 });
    error.value = '';
    notice.value = '';
    try {
        const result = await downloadToBrowser(
            props.account,
            id,
            controller.signal,
            (bytes, total) => jobs.value.set(id, { controller, bytes, total }),
        );
        notice.value = result.persistent
            ? t('Browser download is ready offline.')
            : t('Browser download is ready offline. Persistent storage was not granted; the browser may evict this copy. Browser storage is not a backup.');
        await reload();
    } catch (cause) {
        if (controller.signal.aborted)
            notice.value =
                t('Browser download canceled. Any previous complete copy is preserved.');
        else error.value = message(cause);
    } finally {
        jobs.value.delete(id);
    }
}
async function exportCopy(copy: BrowserCopy): Promise<void> {
    error.value = '';
    try {
        const file = await readBrowserCopy(props.account, copy.id);
        const url = URL.createObjectURL(file);
        const link = document.createElement('a');
        link.href = url;
        link.download = copy.name;
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (cause) {
        error.value = message(cause);
    }
}
const previewableTypes = new Set([
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
]);
function releasePreview(): void {
    previewGeneration++;
    const url = previewUrl.value;
    previewUrl.value = '';
    if (url) URL.revokeObjectURL(url);
}
function closePreview(): void {
    previewOpen.value = false;
    releasePreview();
    previewFile.value = null;
}
function setPreviewOpen(open: boolean): void {
    if (open) {
        previewOpen.value = true;
        return;
    }
    closePreview();
}
function retryPreview(): void {
    if (previewFile.value) void preview(previewFile.value);
}
function downloadPreview(): void {
    if (previewFile.value) void exportCopy(previewFile.value);
}
async function preview(copy: BrowserCopy): Promise<void> {
    releasePreview();
    const generation = previewGeneration;
    previewFile.value = copy;
    previewError.value = '';
    previewOpen.value = true;
    if (!copy.available) {
        previewError.value =
            t('This browser copy is missing or incomplete. Refresh it while online, then try again.');
        return;
    }
    if (!previewableTypes.has(copy.mime_type)) {
        previewError.value =
            t('Preview is unavailable for this format. Download the saved copy instead.');
        return;
    }
    try {
        const file = await readBrowserCopy(props.account, copy.id);
        if (
            generation !== previewGeneration ||
            browserAccount() !== props.account
        )
            return;
        previewUrl.value = URL.createObjectURL(file);
    } catch (cause) {
        if (generation === previewGeneration)
            previewError.value = message(cause);
    }
}
async function remove(copy: BrowserCopy): Promise<void> {
    try {
        await removeBrowserCopy(props.account, copy.id);
        await reload();
    } catch (cause) {
        error.value = message(cause);
    }
}
async function checkOriginals(): Promise<void> {
    if (props.offline || !navigator.onLine) return;
    for (const copy of visibleCopies.value) {
        try {
            const original = await browserOriginal(copy.id);
            await markBrowserOriginalMissing(
                props.account,
                copy.id,
                original === null,
            );
        } catch {
            /* A network/auth failure does not establish deletion. */
        }
    }
    await reload();
}
function updateRetention(): void {
    try {
        retainBrowserCopies(props.account, retain.value);
    } catch (cause) {
        retain.value = false;
        error.value = message(cause);
    }
}
watch(
    () => props.account,
    async (account) => {
        closePreview();
        for (const job of jobs.value.values()) job.controller.abort();
        copies.value = [];
        error.value = '';
        notice.value = '';
        if (!props.offline) setBrowserAccount(account);
        retain.value = retainsBrowserCopies(account);
        await reload();
    },
);
onMounted(async () => {
    try {
        if (!props.offline) setBrowserAccount(props.account);
        if (!browserStorageAvailable())
            throw new Error(
                t('This browser does not support offline file storage. Use a regular download.'),
            );
        retain.value = retainsBrowserCopies(props.account);
        unsubscribe = onBrowserCopiesChanged(() => {
            if (browserAccount() !== props.account) closePreview();
            void reload();
        });
        await reconcileBrowserCopies(props.account);
        await reload();
        await checkOriginals();
        window.addEventListener('online', checkOriginals);
    } catch (cause) {
        error.value = message(cause);
    } finally {
        loading.value = false;
    }
});
onBeforeUnmount(() => {
    closePreview();
    unsubscribe?.();
    window.removeEventListener('online', checkOriginals);
    for (const job of jobs.value.values()) job.controller.abort();
});
defineExpose({ save });
</script>
<template>
    <section
        class="bg-card text-card-foreground space-y-4 rounded-lg border p-4"
        :aria-label="t('Browser downloads')"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold">
                {{ fileId === undefined ? t('Browser downloads') : t('Offline availability') }}
            </h2>
            <a
                v-if="!offline && fileId === undefined"
                :href="offlineShell"
                class="text-sm underline"
                >{{ t('Open saved files offline') }}</a
            >
        </div>
        <p class="text-muted-foreground text-sm">
            {{ t('Independent snapshots in this browser. Removing a copy never deletes the original. Browser storage is not a backup.') }}
        </p>
        <label v-if="fileId === undefined" class="flex items-start gap-2 text-sm"
            ><input
                v-model="retain"
                type="checkbox"
                @change="updateRetention"
            />{{ t('Keep my browser copies after logout on this personal device. Anyone using this browser may read unencrypted copies.') }}</label
        >
        <p v-if="loading" role="status">{{ t('Opening browser downloads…') }}</p>
        <p v-if="error" role="alert">{{ error }}</p>
        <p v-if="notice" role="status">{{ notice }}</p>
        <div
            v-for="[id, job] in jobs"
            v-show="fileId === undefined || fileId === id"
            :key="id"
            class="flex items-center gap-3 text-sm"
        >
            <span>{{
                job.total
                    ? `${formatNumber(job.bytes, locale)} / ${formatNumber(job.total, locale)} ${t('bytes')}`
                    : t('Preparing browser download for file :id…', { id: formatNumber(id, locale) })
            }}</span
            ><progress
                v-if="job.total"
                :value="job.bytes"
                :max="job.total"
            /><Button
                size="sm"
                variant="outline"
                @click="job.controller.abort()"
                >{{ t('Cancel browser download') }}</Button
            >
        </div>
        <p
            v-if="!loading && !visibleCopies.length && fileId === undefined"
            class="text-muted-foreground text-sm"
        >
            {{ t('No complete browser copies for this account.') }}
        </p>
        <div
            v-if="!loading && fileId !== undefined && !visibleCopies.length"
            class="space-y-2"
        >
            <p class="text-muted-foreground text-sm">
                {{ t('Not saved in this browser for offline use.') }}
            </p>
            <Button
                size="sm"
                :disabled="jobs.has(fileId)"
                @click="save(fileId)"
                >{{ t('Save for offline use') }}</Button
            >
        </div>
        <ul class="space-y-3">
            <li
                v-for="copy in visibleCopies"
                :key="copy.id"
                class="space-y-2 rounded border p-3"
                data-test="browser-copy"
            >
                <div class="font-medium break-words">
                    {{ copy.metadata_protected ? t('Protected file') : copy.name }}
                </div>
                <p class="text-muted-foreground text-sm">
                    {{
                        copy.available
                            ? t('Offline ready')
                            : t('Unavailable: missing or incomplete')
                    }}
                    · {{ t('Saved :date', { date: formatDate(copy.saved_at, locale) }) }} ·
                    {{ formatFileSize(copy.size, locale) }}
                </p>
                <p v-if="copy.original_missing" class="text-sm">
                    {{ t('The original is missing. This browser snapshot is still available.') }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="
                            !copy.encryption_header &&
                            previewableTypes.has(copy.mime_type)
                        "
                        size="sm"
                        variant="outline"
                        @click="preview(copy)"
                        >{{ t('Preview') }}</Button
                    ><Button
                        v-if="!copy.encryption_header"
                        size="sm"
                        @click="exportCopy(copy)"
                        >{{ t('Download saved copy') }}</Button
                    ><Button
                        v-if="!offline && !copy.original_missing"
                        size="sm"
                        variant="outline"
                        @click="save(copy.id)"
                        >{{ t('Refresh snapshot') }}</Button
                    ><Button size="sm" variant="outline" @click="remove(copy)"
                        >{{ t('Remove browser copy') }}</Button
                    >
                </div>
                <OfflineEncryptedCopy
                    v-if="copy.encryption_header"
                    :account="props.account"
                    :copy="copy"
                />
            </li>
        </ul>
    </section>
    <MediaViewer
        :open="previewOpen"
        :file="previewFile"
        :url="previewUrl"
        :error="previewError"
        @update:open="setPreviewOpen"
        @retry="retryPreview"
        @download="downloadPreview"
    />
</template>
