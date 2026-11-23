<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef } from 'vue';
import { useHttp } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import MediaCover from '@/components/MediaCover.vue';
import { Button } from '@/components/ui/button';
import {
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    browserOriginal,
    preparedDownloadUrl,
} from '@/lib/browserDownloadTransfer';
import {
    browserAccount,
    browserLogoutEpoch,
    readBrowserCopy,
} from '@/lib/browserDownloads';
import {
    encryptMetadata,
    stageDownload,
    validateMediaDetails,
    type MetadataEnvelope,
    type ProtectedMetadata,
} from '@/lib/contentsEncryption';
import {
    decryptBrowserCopyStream,
    unlockBrowserCopy,
} from '@/lib/encryptedBrowserCopy';
import {
    readEmbeddedMediaMetadata,
    readEmbeddedMediaStream,
    type MediaDetails,
} from '@/lib/mediaMetadata';
import { update as updateTrack } from '@/routes/media/tracks';
import { index as filesIndex, protectedMetadata } from '@/routes/files';

const props = defineProps<{
    account: number;
    track: {
        id: number;
        name: string;
        encrypted: boolean;
        protected: boolean;
        details?: MediaDetails | null;
    };
    artwork: { id: number; name: string }[];
    savedCopy: boolean;
}>();
const emit = defineEmits<{ saved: [] }>();

const scalars = [
    ['title', 'Title'],
    ['artist', 'Artist'],
    ['album', 'Album'],
    ['genre', 'Genre'],
] as const;
const coverFields = [
    ['cover_file_id', 'Track cover'],
    ['album_cover_file_id', 'Album cover'],
] as const;
const values = ref<MediaDetails>({ ...props.track.details });
const credential = ref('');
const recovery = ref(false);
const unlockingContent = ref(false);
const busy = ref(false);
const error = ref('');
const request = new AbortController();
const epoch = browserLogoutEpoch(props.account);
const privateDetails = shallowRef<{
    key: CryptoKey;
    metadata: ProtectedMetadata | null;
    previous: MetadataEnvelope | null;
    identity: string;
    connection: number;
    read: () => Promise<MediaDetails>;
}>();
const locked = computed(
    () =>
        (props.track.protected || unlockingContent.value) &&
        !privateDetails.value,
);
const ordinary = useHttp<{ details: MediaDetails }>({ details: {} });
const encrypted = useHttp<{
    previous_metadata: MetadataEnvelope | null;
    protected_metadata: MetadataEnvelope | null;
}>({ previous_metadata: null, protected_metadata: null });

function checkSession(): void {
    request.signal.throwIfAborted();
    if (
        browserAccount() !== props.account ||
        browserLogoutEpoch(props.account) !== epoch
    ) {
        privateDetails.value = undefined;
        values.value = {};
        throw new Error('The active account changed. Reopen this track.');
    }
}

async function unlock(): Promise<void> {
    const secret = credential.value;
    credential.value = '';
    if (!secret || busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        checkSession();
        const snapshot = await browserOriginal(props.track.id, request.signal);
        if (!snapshot?.connection_id)
            throw new Error('Track details are unavailable.');
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
            secret,
            recovery.value,
        );
        checkSession();
        privateDetails.value = {
            key,
            metadata,
            previous: snapshot.protected_metadata,
            identity: snapshot.storage_name,
            connection: snapshot.connection_id,
            read: async () => {
                const encrypted = await readBrowserCopy(
                    props.account,
                    props.track.id,
                )
                    .then((file) => ({ file, dispose: async () => {} }))
                    .catch(async () =>
                        stageDownload(
                            await fetch(
                                await preparedDownloadUrl(
                                    props.track.id,
                                    request.signal,
                                ),
                                {
                                    signal: request.signal,
                                },
                            ),
                            snapshot.size,
                            request.signal,
                        ),
                    );
                try {
                    return await readEmbeddedMediaStream(
                        await decryptBrowserCopyStream(
                            copy,
                            props.account,
                            key,
                            encrypted.file,
                            request.signal,
                        ),
                    );
                } finally {
                    await encrypted.dispose();
                }
            },
        };
        if (props.track.protected) values.value = { ...metadata?.media };
        unlockingContent.value = false;
    } catch (cause) {
        error.value =
            cause instanceof Error
                ? cause.message
                : 'Could not unlock the track.';
    } finally {
        busy.value = false;
    }
}

async function importEmbedded(): Promise<void> {
    if (props.track.encrypted && !privateDetails.value) {
        unlockingContent.value = true;
        error.value = '';
        return;
    }
    busy.value = true;
    error.value = '';
    try {
        checkSession();
        const privateTrack = privateDetails.value;
        const details = privateTrack
            ? await privateTrack.read()
            : await readEmbeddedMediaMetadata(
                  props.savedCopy
                      ? await readBrowserCopy(props.account, props.track.id)
                      : await fetch(
                            await preparedDownloadUrl(
                                props.track.id,
                                request.signal,
                                true,
                            ),
                            {
                                headers: { Range: 'bytes=0-2097161' },
                                signal: request.signal,
                            },
                        ).then(async (response) => {
                            if (!response.ok)
                                throw new Error('Could not read the track.');
                            return response.blob();
                        }),
              );
        checkSession();
        if (!Object.keys(details).length) {
            error.value = 'No supported embedded metadata was found.';
            return;
        }
        values.value = { ...values.value, ...details };
    } catch (cause) {
        error.value =
            cause instanceof Error
                ? cause.message
                : 'Could not read embedded metadata.';
    } finally {
        busy.value = false;
    }
}

async function save(): Promise<void> {
    if (locked.value || busy.value) return;
    error.value = '';
    busy.value = true;
    try {
        checkSession();
        const details = { ...values.value };
        if (typeof details.track_number !== 'number')
            details.track_number = null;
        validateMediaDetails(details);
        const privateTrack = privateDetails.value;
        const onError = () => {
            error.value =
                'Could not save track details. Check the values and try again.';
        };
        const onSuccess = () => {
            toast.success('Track details saved');
            emit('saved');
        };
        if (props.track.protected && privateTrack?.metadata) {
            const envelope = await encryptMetadata(
                { ...privateTrack.metadata, media: details },
                privateTrack.key,
                {
                    userId: props.account,
                    connectionId: privateTrack.connection,
                },
                privateTrack.identity,
            );
            checkSession();
            await encrypted
                .transform(() => ({
                    previous_metadata: privateTrack.previous,
                    protected_metadata: envelope,
                }))
                .patch(protectedMetadata.url({ file: props.track.id }), {
                    onError,
                    onSuccess,
                });
        } else if (!props.track.protected) {
            await ordinary
                .transform(() => ({ details }))
                .patch(updateTrack.url(props.track.id), { onError, onSuccess });
        }
    } catch (cause) {
        error.value =
            cause instanceof Error
                ? cause.message
                : 'Could not save track details.';
    } finally {
        busy.value = false;
    }
}

onBeforeUnmount(() => {
    request.abort();
    ordinary.cancel();
    encrypted.cancel();
    privateDetails.value = undefined;
});
</script>

<template>
    <DialogContent class="max-h-[90dvh] overflow-y-auto">
        <DialogTitle>Track details</DialogTitle>
        <DialogDescription>{{
            track.protected ? 'Protected track' : track.name
        }}</DialogDescription>
        <form v-if="locked" class="space-y-3" @submit.prevent="unlock">
            <Input
                v-model="credential"
                type="password"
                :aria-label="recovery ? 'Recovery key' : 'Vault password'"
                :placeholder="recovery ? 'Recovery key' : 'Vault password'"
            />
            <label class="flex gap-2"
                ><input v-model="recovery" type="checkbox" /> Use recovery
                key</label
            >
            <Button type="submit" :disabled="busy || !credential"
                >Unlock</Button
            >
            <Button
                v-if="unlockingContent"
                type="button"
                variant="ghost"
                :disabled="busy"
                @click="unlockingContent = false"
                >Back</Button
            >
        </form>
        <form v-else class="space-y-4" @submit.prevent="save">
            <div class="grid grid-cols-2 gap-3">
                <label
                    v-for="[field, label] in scalars"
                    :key="field"
                    class="space-y-1 text-sm"
                >
                    <span>{{ label }}</span>
                    <Input
                        :model-value="values[field] ?? ''"
                        maxlength="255"
                        @update:model-value="
                            values[field] = String($event) || null
                        "
                    />
                </label>
                <label class="space-y-1 text-sm">
                    <span>Track number</span>
                    <Input
                        :model-value="values.track_number ?? ''"
                        @update:model-value="
                            values.track_number =
                                $event === '' ? null : Number($event)
                        "
                        type="number"
                        min="1"
                        max="9999"
                    />
                </label>
            </div>
            <label class="block space-y-1 text-sm">
                <span>Lyrics</span>
                <textarea
                    v-model="values.lyrics"
                    maxlength="10000"
                    rows="4"
                    class="bg-background w-full rounded-md border p-2"
                />
            </label>
            <div class="grid grid-cols-2 gap-3">
                <label
                    v-for="[field, label] in coverFields"
                    :key="field"
                    class="block space-y-1 text-sm"
                >
                    <span>{{ label }}</span>
                    <select
                        :value="values[field] ?? ''"
                        @change="
                            values[field] =
                                Number(
                                    ($event.target as HTMLSelectElement).value,
                                ) || null
                        "
                        class="bg-background w-full rounded-md border p-2"
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
                    <MediaCover
                        v-if="!track.protected"
                        :file-id="values[field]"
                        :alt="label"
                    />
                </label>
            </div>
            <p class="text-muted-foreground text-sm">
                <a
                    :href="filesIndex.url()"
                    target="_blank"
                    rel="noopener"
                    class="underline"
                    >Upload artwork in Files</a
                >
                and reopen track details to select it.
            </p>
            <DialogFooter>
                <Button
                    type="button"
                    variant="secondary"
                    :disabled="busy"
                    @click="importEmbedded"
                    >Read embedded tags</Button
                >
                <Button type="submit" :disabled="busy">Save details</Button>
            </DialogFooter>
        </form>
        <p v-if="error" role="alert" class="text-destructive text-sm">
            {{ error }}
        </p>
    </DialogContent>
</template>
