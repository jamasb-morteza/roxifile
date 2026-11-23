<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    changeVaultPassword,
    createRecoveryKit,
    createVault,
    readRecoveryKit,
    unlockVault,
} from '@/lib/contentsEncryption';
import type { VaultContext, VaultEnvelope } from '@/lib/contentsEncryption';
import {
    browserLogoutEpoch,
    onBrowserCopiesChanged,
} from '@/lib/browserDownloads';
import { encryption } from '@/routes/telegram/connections';

const props = defineProps<{
    context: VaultContext;
    mode: string;
    envelope?: VaultEnvelope | null;
    canConfigure?: boolean;
}>();
const emit = defineEmits<{
    configured: [];
    unlocked: [key: CryptoKey];
    locked: [connectionId: number];
}>();
const password = ref('');
const scope = ref<'contents' | 'contents_and_metadata'>('contents');
const newPassword = ref('');
const useRecovery = ref(false);
const activeEnvelope = ref(props.envelope ?? null);
const previousEnvelope = ref<VaultEnvelope | null>(null);
const notice = ref('');
const error = ref('');
const busy = ref(false);
const unlocked = ref(false);
const recoverySecret = ref('');
const recoverySaved = ref(false);
const pendingEnvelope = ref<VaultEnvelope | null>(null);
const form = useHttp({
    envelope: null as VaultEnvelope | null,
    scope: undefined as 'contents' | 'contents_and_metadata' | undefined,
    previous_envelope: undefined as VaultEnvelope | undefined,
});
let generation = 0;
let logoutEpoch = browserLogoutEpoch(props.context.userId);
let unsubscribeBrowserCopies: (() => void) | undefined;

function reset(): void {
    generation++;
    busy.value = false;
    password.value = '';
    newPassword.value = '';
    notice.value = '';
    error.value = '';
    previousEnvelope.value = null;
    form.previous_envelope = undefined;
    recoverySecret.value = '';
    pendingEnvelope.value = null;
    form.envelope = null;
    recoverySaved.value = false;
    unlocked.value = false;
    emit('locked', props.context.connectionId);
}
watch(
    [() => props.context.userId, () => props.context.connectionId],
    (_current, previous) => {
        emit('locked', previous[1]);
        reset();
        activeEnvelope.value = props.envelope ?? null;
        logoutEpoch = browserLogoutEpoch(_current[0]);
    },
);
watch(
    () => props.envelope,
    (envelope) => {
        if (JSON.stringify(envelope) !== JSON.stringify(activeEnvelope.value)) {
            reset();
            activeEnvelope.value = envelope ?? null;
        }
    },
);
onBeforeUnmount(reset);
onMounted(() => {
    window.addEventListener('pagehide', reset);
    unsubscribeBrowserCopies = onBrowserCopiesChanged(() => {
        const currentLogoutEpoch = browserLogoutEpoch(props.context.userId);
        if (currentLogoutEpoch !== logoutEpoch) {
            logoutEpoch = currentLogoutEpoch;
            reset();
        }
    });
});
onBeforeUnmount(() => window.removeEventListener('pagehide', reset));
onBeforeUnmount(() => unsubscribeBrowserCopies?.());

async function prepare(): Promise<void> {
    const current = generation;
    error.value = '';
    busy.value = true;
    try {
        const vault = await createVault(password.value, props.context);
        if (current !== generation) return;
        pendingEnvelope.value = vault.envelope;
        recoverySecret.value = vault.recoverySecret;
    } catch (reason) {
        if (current !== generation) return;
        error.value =
            reason instanceof Error
                ? reason.message
                : 'Encryption setup failed.';
    } finally {
        if (current === generation) {
            password.value = '';
            busy.value = false;
        }
    }
}

function downloadRecovery(): void {
    const blob = new Blob(
        [
            JSON.stringify(
                {
                    format: 'roxifile-recovery-v1',
                    ...props.context,
                    recoverySecret: recoverySecret.value,
                    envelope: pendingEnvelope.value,
                },
                null,
                2,
            ),
        ],
        { type: 'application/json' },
    );
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `roxifile-recovery-${props.context.connectionId}.json`;
    anchor.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

async function configure(): Promise<void> {
    if (!pendingEnvelope.value || !recoverySaved.value || form.processing)
        return;
    form.envelope = pendingEnvelope.value;
    form.scope = props.mode === 'none' ? scope.value : undefined;
    form.previous_envelope = previousEnvelope.value ?? undefined;
    const replacement = pendingEnvelope.value;
    const current = generation;
    try {
        await form.post(encryption(props.context.connectionId).url, {
            onSuccess: () => {
                if (current !== generation) return;
                activeEnvelope.value = replacement;
                reset();
                notice.value =
                    'Vault credentials saved. Unlock with your current credentials.';
                emit('configured');
            },
            onError: (errors) => {
                if (current === generation)
                    error.value = Object.values(errors).join(' ');
            },
        });
    } catch {
        if (current === generation)
            error.value =
                'Could not save vault credentials. Check your connection and try again.';
    }
}

async function unlock(): Promise<void> {
    if (!activeEnvelope.value || busy.value || form.processing) return;
    busy.value = true;
    const current = generation;
    error.value = '';
    try {
        const key = await unlockVault(
            activeEnvelope.value,
            password.value,
            props.context,
            useRecovery.value,
        );
        if (current !== generation) return;
        unlocked.value = true;
        emit('unlocked', key);
    } catch (reason) {
        if (current !== generation) return;
        error.value =
            reason instanceof Error ? reason.message : 'Unlock failed.';
    } finally {
        if (current === generation) {
            password.value = '';
            busy.value = false;
        }
    }
}
async function importRecovery(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    const current = generation;
    input.value = '';
    if (!file) return;
    error.value = '';
    try {
        if (file.size > 16384)
            throw new Error('This is not a RoxiFile recovery file.');
        const contents = await file.text();
        if (current !== generation) return;
        password.value = readRecoveryKit(contents, props.context);
        useRecovery.value = true;
    } catch (reason) {
        if (current !== generation) return;
        password.value = '';
        error.value =
            reason instanceof Error ? reason.message : 'Invalid recovery file.';
    }
}

async function prepareRecovery(): Promise<void> {
    if (!activeEnvelope.value || busy.value || form.processing) return;
    const current = generation;
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const original = activeEnvelope.value;
        const kit = await createRecoveryKit(
            original,
            password.value,
            props.context,
            useRecovery.value,
        );
        if (current !== generation) return;
        previousEnvelope.value = original;
        pendingEnvelope.value = kit.envelope;
        recoverySecret.value = kit.recoverySecret;
        recoverySaved.value = false;
    } catch (reason) {
        if (current !== generation) return;
        error.value =
            reason instanceof Error
                ? reason.message
                : 'Recovery export failed.';
    } finally {
        if (current === generation) {
            password.value = '';
            busy.value = false;
        }
    }
}

async function changePassword(): Promise<void> {
    if (!activeEnvelope.value || busy.value || form.processing) return;
    const current = generation;
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const original = activeEnvelope.value;
        const replacement = await changeVaultPassword(
            original,
            password.value,
            newPassword.value,
            props.context,
            useRecovery.value,
        );
        if (current !== generation) return;
        form.envelope = replacement;
        form.previous_envelope = original;
        await form.post(encryption(props.context.connectionId).url, {
            onSuccess: () => {
                if (current !== generation) return;
                activeEnvelope.value = replacement;
                reset();
                notice.value =
                    'Vault password changed. Existing files and your saved recovery key still work.';
                emit('configured');
            },
            onError: (errors) => {
                if (current === generation)
                    error.value = Object.values(errors).join(' ');
            },
        });
    } catch (reason) {
        if (current !== generation) return;
        error.value =
            reason instanceof Error
                ? reason.message
                : 'Password change failed.';
    } finally {
        if (current === generation) {
            password.value = '';
            newPassword.value = '';
            form.envelope = null;
            form.previous_envelope = undefined;
            busy.value = false;
        }
    }
}
</script>

<template>
    <section
        v-if="mode !== 'none' || canConfigure"
        class="flex flex-col gap-3 rounded-lg border p-4"
        aria-label="Connection encryption"
    >
        <template v-if="mode === 'none'">
            <p class="font-medium">Enable device encryption (preview)</p>
            <p class="text-muted-foreground text-sm">
                Files are encrypted on this device. Contents-only encryption
                leaves names, types, tags and descriptions readable. Contents
                and metadata encryption protects those fields too; folder names
                and hierarchy, ciphertext sizes and transfer timing remain
                readable in both scopes. A compromised device or replaced web
                client can read unlocked files. Use a separate vault password;
                resetting your account password cannot recover this vault. Keep
                your recovery key safe. Encryption cannot be changed after
                setup; ordinary connections also lock their mode on the first
                upload. Keep the recovery file outside this browser.
            </p>
            <template v-if="!pendingEnvelope">
                <Label :for="`vault-scope-${context.connectionId}`"
                    >Encryption scope</Label
                >
                <select
                    :id="`vault-scope-${context.connectionId}`"
                    v-model="scope"
                    class="bg-background rounded-md border p-2"
                    :disabled="busy"
                >
                    <option value="contents">Contents only</option>
                    <option value="contents_and_metadata">
                        Contents and metadata (folder paths remain readable)
                    </option>
                </select>
                <Label :for="`vault-password-${context.connectionId}`"
                    >Separate vault password (12+ characters)</Label
                >
                <Input
                    :id="`vault-password-${context.connectionId}`"
                    v-model="password"
                    type="password"
                    autocomplete="new-password"
                    :disabled="busy"
                />
                <Button type="button" :disabled="busy" @click="prepare">{{
                    busy ? 'Preparing…' : 'Prepare encryption'
                }}</Button>
            </template>
            <template v-else>
                <p class="text-sm">
                    Save the recovery file before enabling encryption. Losing
                    both credentials means losing your files.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    @click="downloadRecovery"
                    >Download recovery key</Button
                >
                <label class="flex items-center gap-2 text-sm"
                    ><input v-model="recoverySaved" type="checkbox" />I saved
                    the recovery file safely.</label
                >
                <Button
                    type="button"
                    :disabled="!recoverySaved || form.processing"
                    @click="configure"
                    >Enable encryption</Button
                >
                <Button
                    type="button"
                    variant="ghost"
                    :disabled="form.processing"
                    @click="reset"
                    >Cancel</Button
                >
            </template>
        </template>
        <template v-else>
            <p class="font-medium">
                {{
                    mode === 'contents_and_metadata'
                        ? 'Contents and metadata encrypted'
                        : 'Contents encrypted'
                }}
                ·
                {{ unlocked ? 'Unlocked on this page' : 'Locked' }}
            </p>
            <template v-if="activeEnvelope && !pendingEnvelope">
                <Label :for="`vault-credential-type-${context.connectionId}`"
                    >Unlock using</Label
                >
                <select
                    :id="`vault-credential-type-${context.connectionId}`"
                    v-model="useRecovery"
                    :disabled="busy || form.processing"
                    class="bg-background rounded-md border p-2 text-sm"
                    @change="password = ''"
                >
                    <option :value="false">Vault password</option>
                    <option :value="true">Recovery key</option>
                </select>
                <Label :for="`vault-unlock-${context.connectionId}`">{{
                    useRecovery ? 'Recovery key' : 'Vault password'
                }}</Label>
                <Input
                    :id="`vault-unlock-${context.connectionId}`"
                    v-model="password"
                    type="password"
                    autocomplete="off"
                    :disabled="busy || form.processing"
                    @keydown.enter.prevent="unlock"
                />
                <Label :for="`vault-import-${context.connectionId}`"
                    >Or import your saved recovery file</Label
                >
                <input
                    :id="`vault-import-${context.connectionId}`"
                    type="file"
                    accept=".json,application/json"
                    :disabled="busy || form.processing"
                    class="text-sm"
                    @change="importRecovery"
                />
                <Button
                    v-if="!unlocked"
                    type="button"
                    :disabled="busy || form.processing"
                    @click="unlock"
                    >{{ busy ? 'Unlocking…' : 'Unlock on this device' }}</Button
                >
                <Label :for="`vault-new-password-${context.connectionId}`"
                    >New vault password (12+ characters)</Label
                >
                <Input
                    :id="`vault-new-password-${context.connectionId}`"
                    v-model="newPassword"
                    type="password"
                    autocomplete="new-password"
                    :disabled="busy || form.processing"
                />
                <Button
                    type="button"
                    variant="outline"
                    :disabled="
                        busy || form.processing || !password || !newPassword
                    "
                    @click="changePassword"
                    >Change vault password</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    :disabled="busy || form.processing || !password"
                    @click="prepareRecovery"
                    >Prepare replacement recovery key</Button
                >
            </template>
            <template v-if="pendingEnvelope">
                <p class="text-sm">
                    Save this replacement recovery file, then activate it. Your
                    previous recovery key will no longer unlock the current
                    vault.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    @click="downloadRecovery"
                    >Download recovery key</Button
                >
                <label class="flex items-center gap-2 text-sm"
                    ><input v-model="recoverySaved" type="checkbox" />I saved
                    the replacement recovery file safely.</label
                >
                <Button
                    type="button"
                    :disabled="!recoverySaved || form.processing"
                    @click="configure"
                    >Activate replacement recovery key</Button
                >
                <Button
                    type="button"
                    variant="ghost"
                    :disabled="form.processing"
                    @click="reset"
                    >Cancel</Button
                >
            </template>
            <p
                v-if="!activeEnvelope"
                role="alert"
                class="text-destructive text-sm"
            >
                Vault material is unavailable. Reload this page. An account
                password reset cannot restore missing vault material.
            </p>
            <Button
                v-if="unlocked"
                :disabled="form.processing"
                type="button"
                variant="outline"
                @click="reset"
                >Lock vault and cancel local transfers</Button
            >
            <p class="text-muted-foreground text-sm">
                Keys stay in page memory. Leaving this page locks the vault.
                Your account password cannot unlock or recover this vault.
                Losing both vault credentials means losing access to your files.
                Changing credentials cannot revoke old credentials or ciphertext
                that someone already retained.
            </p>
        </template>
        <p v-if="notice" role="status" class="text-sm">{{ notice }}</p>
        <p v-if="error" role="alert" class="text-destructive text-sm">
            {{ error }}
        </p>
    </section>
</template>
