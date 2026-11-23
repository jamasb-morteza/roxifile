<script setup lang="ts">
import { useLocalization } from '@/composables/useLocalization';
import { Head, router, useHttp, usePoll } from '@inertiajs/vue3';
import {
    Bot,
    CheckCircle2,
    CircleAlert,
    Copy,
    Link2,
    Plus,
    Pencil,
    RefreshCw,
    ShieldCheck,
    TriangleAlert,
    UserPlus,
    Wifi,
    WifiOff,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTelegramEvents } from '@/composables/useTelegramEvents';
import ConnectionVault from '@/components/ConnectionVault.vue';
import { usePage } from '@inertiajs/vue3';
import * as connectionsRoutes from '@/routes/telegram/connections';
import { start as startBot } from '@/routes/telegram/bot';
import { start as startInvite } from '@/routes/telegram/invite';
import {
    loginCode as personalLoginCode,
    loginPassword as personalLoginPassword,
    reconnect as personalReconnect,
    start as startPersonal,
} from '@/routes/telegram/personal';
import { workerStatus as workerStatusRoute } from '@/routes/telegram';

type Connection = {
    id: number;
    name: string;
    method: string;
    status: string;
    personal_login_step: string | null;
    chat_title: string | null;
    chat_type: string | null;
    files_count: number;
    vault_envelope: import('@/lib/contentsEncryption').VaultEnvelope | null;
    encryption_mode: string;
    encryption_locked: boolean;
    last_verified_at: string | null;
    last_error: string | null;
    provision_code_masked: string | null;
    provision_code_expires_at: string | null;
    provisioning_state:
        | 'awaiting_code'
        | 'awaiting_confirmation_delivery'
        | 'awaiting_confirmation'
        | 'confirmed'
        | 'expired'
        | 'failed'
        | null;
    needs_storage_chat: boolean;
    identity_verified: boolean;
    setup_mode: 'existing' | 'create';
    chat_setup: {
        status:
            | 'queued'
            | 'running'
            | 'complete'
            | 'awaiting_join'
            | 'failed'
            | 'cancelled'
            | null;
        chats: { id: number; title: string; type: string }[];
        error: string | null;
        join_link: string | null;
        created_title: string | null;
        group_name: string | null;
    };
};

type Props = {
    connections: Connection[];
    roxi_username: string;
    contents_encryption_enabled: boolean;
};

const props = defineProps<Props>();
const vaultUserId = Number(usePage().props.auth.user.id);

// ── Worker health strip (spec §41) ──────────────────────────────────

type WorkerHealth = {
    running: boolean;
    roxi_connected: boolean;
    session_loaded: boolean;
    last_activity_at: string | null;
    last_successful_operation_at: string | null;
    last_error: string | null;
    reconnect_count: number;
    flagged_files: number;
};

const workerHealth = ref<WorkerHealth | null>(null);
let healthTimer: ReturnType<typeof setInterval> | undefined;

async function refreshHealth(): Promise<void> {
    try {
        const response = await fetch(workerStatusRoute.url(), {
            headers: { Accept: 'application/json' },
        });

        if (response.ok) {
            workerHealth.value = (await response.json()) as WorkerHealth;
        }
    } catch {
        workerHealth.value = null;
    }
}

onMounted(() => {
    void refreshHealth();
    healthTimer = setInterval(() => void refreshHealth(), 30_000);
});

onUnmounted(() => clearInterval(healthTimer));

// ── Storage chat wizard ───────────────────────────────────────────

type WizardMethod = 'bot' | 'invite' | 'personal';
type ProvisionState =
    | 'submitting'
    | 'waiting'
    | 'awaiting_confirmation_delivery'
    | 'awaiting_confirmation'
    | 'expired'
    | 'chat_detected'
    | 'connected'
    | 'done'
    | 'failed';
type WizardMode = 'create' | 'regenerate';

const wizardOpen = ref(false);
const wizardStep = ref<1 | 2 | 3>(1);
const wizardMethod = ref<WizardMethod | null>(null);
const wizardMode = ref<WizardMode>('create');
const wizardName = ref('');
const personalPhone = ref('');
const personalCode = ref('');
const personalPassword = ref('');
const personalConnectionId = ref<number | null>(null);
const personalLoginStep = ref<string | null>(null);
const botToken = ref('');
const inviteTarget = ref('');
const inviteSetupMode = ref<'existing' | 'create'>('existing');
const inviteGroupName = ref('');
const confirmationCode = ref('');
const provState = ref<ProvisionState>('submitting');
const provError = ref<string | null>(null);
const provisioning = ref<{
    connectionId: number | null;
    code: string | null;
    deepLink: string | null;
    expiresAt: string | null;
    targetLabel: string | null;
} | null>(null);
const activeRoxiConnection = computed(
    () =>
        props.connections.find(
            (connection) =>
                connection.id === provisioning.value?.connectionId &&
                connection.method === 'roxi',
        ) ?? null,
);

// JSON endpoint — standalone HTTP, not an Inertia visit.
const botForm = useHttp({ name: '', token: '' });

const wizardTitle = computed((): string => {
    if (wizardStep.value === 1) {
        return 'Connect Chat';
    }

    if (wizardStep.value === 2) {
        return 'Telegram access method';
    }

    if (wizardMethod.value === 'personal') {
        return personalLoginStep.value === 'awaiting_password'
            ? 'Two-Step Verification'
            : 'Sign in to personal Telegram';
    }

    if (
        activeRoxiConnection.value &&
        provState.value === 'awaiting_confirmation'
    ) {
        return 'Confirm Roxi connection';
    }

    return wizardMode.value === 'regenerate'
        ? 'Provisioning Code'
        : 'Provisioning';
});

const wizardDescription = computed((): string => {
    if (wizardStep.value === 1) {
        return 'Give this storage setup a name in RoxiFile. You will choose or create the Telegram chat where files are stored.';
    }

    if (wizardStep.value === 2) {
        return 'Choose how RoxiFile accesses your storage chat. Bot and personal Telegram access are optional alternatives to Roxi.';
    }

    if (wizardMethod.value === 'personal') {
        return 'Sign in to your optional personal Telegram account. Telegram may also request a two-step verification password.';
    }

    if (activeRoxiConnection.value) {
        return 'Confirm that you control both RoxiFile and the Telegram chat before storage is connected.';
    }

    return 'Verify your Telegram identity, then finish setting up your storage chat.';
});

const canStartProvisioning = computed((): boolean => {
    if (wizardMethod.value === 'bot') {
        return botToken.value.trim() !== '';
    }

    if (wizardMethod.value === 'invite') {
        return (
            inviteTarget.value.trim() !== '' &&
            (inviteSetupMode.value !== 'create' ||
                inviteGroupName.value.trim() !== '')
        );
    }

    if (wizardMethod.value === 'personal') {
        return /^\+[1-9][0-9]{5,14}$/.test(personalPhone.value.trim());
    }

    return false;
});

function openWizard(): void {
    wizardStep.value = 1;
    wizardMethod.value = null;
    wizardMode.value = 'create';
    wizardName.value = '';
    personalPhone.value = '';
    personalCode.value = '';
    personalPassword.value = '';
    personalConnectionId.value = null;
    personalLoginStep.value = null;
    botToken.value = '';
    inviteTarget.value = '';
    inviteSetupMode.value = 'existing';
    inviteGroupName.value = '';
    confirmationCode.value = '';
    provisioning.value = null;
    provState.value = 'submitting';
    provError.value = null;
    botForm.reset();
    wizardOpen.value = true;
}

function setWizardOpen(open: boolean): void {
    wizardOpen.value = open;

    if (!open) {
        personalPhone.value = '';
        personalCode.value = '';
        personalPassword.value = '';
    }
}

function startProvisioning(): void {
    if (!canStartProvisioning.value) {
        return;
    }

    provError.value = null;
    confirmationCode.value = '';
    provisioning.value = null;
    provState.value = 'submitting';
    wizardStep.value = 3;

    if (wizardMethod.value === 'bot') {
        botForm.name = wizardName.value.trim();
        botForm.token = botToken.value.trim();
        botForm.post(startBot.url(), {
            onSuccess: () => {
                provState.value = 'done';
                toast.success(
                    'Bot storage created. Message the bot in Telegram to start storing files.',
                );
                reloadConnections();
            },
            onError: (errors) => {
                provState.value = 'failed';
                provError.value =
                    errors.token ??
                    errors.name ??
                    'Could not create the bot connection.';
            },
        });

        return;
    }

    if (wizardMethod.value === 'personal') {
        const reconnectUrl =
            personalConnectionId.value === null
                ? null
                : personalReconnect({ connection: personalConnectionId.value })
                      .url;

        void fetch(reconnectUrl ?? startPersonal.url(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(
                reconnectUrl
                    ? { phone: personalPhone.value.trim() }
                    : {
                          name: wizardName.value.trim(),
                          phone: personalPhone.value.trim(),
                      },
            ),
        })
            .then(async (response): Promise<void> => {
                if (!response.ok) {
                    const body = (await response.json().catch(() => null)) as {
                        message?: string;
                    } | null;
                    provState.value = 'failed';
                    provError.value =
                        body?.message ?? 'Could not start Telegram sign-in.';

                    return;
                }

                const body = (await response.json()) as {
                    connection: { id: number; personal_login_step?: string };
                };
                personalConnectionId.value = body.connection.id;
                personalLoginStep.value =
                    body.connection.personal_login_step ?? 'requesting_code';
                personalPhone.value = '';
                provisioning.value = {
                    connectionId: body.connection.id,
                    code: null,
                    deepLink: null,
                    expiresAt: null,
                    targetLabel: null,
                };
                provState.value = 'waiting';
                reloadConnections();
            })
            .catch(() => {
                provState.value = 'failed';
                provError.value =
                    'Could not start Telegram sign-in. Try again.';
            });

        return;
    }

    // JSON endpoint — standalone HTTP, not an Inertia visit.
    void fetch(startInvite.url(), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
            name: wizardName.value.trim(),
            target: inviteTarget.value.trim(),
            setup_mode: inviteSetupMode.value,
            group_name:
                inviteSetupMode.value === 'create'
                    ? inviteGroupName.value.trim()
                    : null,
        }),
    })
        .then(async (response): Promise<void> => {
            if (!response.ok) {
                const body = (await response.json().catch(() => null)) as {
                    message?: string;
                } | null;
                provState.value = 'failed';
                provError.value =
                    body?.message ??
                    'Could not start the invite. Check the @username or mobile number.';

                return;
            }

            const body = (await response.json()) as {
                connection: { id: number };
                code: string;
                deep_link: string;
                expires_at: string | null;
            };

            provisioning.value = {
                connectionId: body.connection.id,
                code: body.code,
                deepLink: body.deep_link,
                expiresAt: body.expires_at,
                targetLabel: inviteTarget.value.trim(),
            };
            provState.value = 'waiting';
            reloadConnections();
        })
        .catch(() => {
            provState.value = 'failed';
            provError.value = 'Could not start the invite. Try again.';
        });
}

// Row action: reissue the code for a pending invite/MTProto connection and
// reopen the wizard directly at the provisioning step.
async function regenerateCode(connection: Connection): Promise<void> {
    wizardMode.value = 'regenerate';
    wizardMethod.value = 'invite';
    wizardName.value = connection.name;
    provisioning.value = {
        connectionId: connection.id,
        code: null,
        deepLink: null,
        expiresAt: null,
        targetLabel: null,
    };
    provState.value = 'submitting';
    provError.value = null;
    confirmationCode.value = '';
    wizardStep.value = 3;
    wizardOpen.value = true;

    try {
        const response = await fetch(
            connectionsRoutes.regenerateCode({ connection: connection.id }).url,
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
            },
        );

        if (!response.ok) {
            provState.value = 'failed';
            provError.value = 'Could not regenerate the code.';

            return;
        }

        const body = (await response.json()) as {
            code: string;
            deep_link: string;
            expires_at: string | null;
        };

        provisioning.value = {
            connectionId: connection.id,
            code: body.code,
            deepLink: body.deep_link,
            expiresAt: body.expires_at,
            targetLabel: null,
        };
        provState.value = 'waiting';
        reloadConnections();
    } catch {
        provState.value = 'failed';
        provError.value = 'Could not regenerate the code.';
    }
}

function openRoxiConfirmation(connection: Connection): void {
    wizardOpen.value = true;
    wizardStep.value = 3;
    wizardMethod.value = 'invite';
    wizardMode.value = 'regenerate';
    wizardName.value = connection.name;
    confirmationCode.value = '';
    provisioning.value = {
        connectionId: connection.id,
        code: null,
        deepLink: null,
        expiresAt: connection.provision_code_expires_at,
        targetLabel: null,
    };
    provState.value = 'awaiting_confirmation';
    provError.value = connection.last_error;
}

async function submitRoxiConfirmation(): Promise<void> {
    const connectionId = provisioning.value?.connectionId;
    if (
        connectionId === null ||
        connectionId === undefined ||
        confirmationCode.value.trim() === ''
    ) {
        return;
    }

    provError.value = null;
    provState.value = 'submitting';

    try {
        const response = await fetch(
            connectionsRoutes.confirmProvisioning({ connection: connectionId })
                .url,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ code: confirmationCode.value.trim() }),
            },
        );
        const body = (await response.json().catch(() => null)) as {
            message?: string;
            errors?: { code?: string[] };
        } | null;

        if (!response.ok) {
            provError.value =
                body?.errors?.code?.[0] ??
                body?.message ??
                'Could not confirm this Telegram chat.';
            provState.value = provError.value.toLowerCase().includes('expired')
                ? 'expired'
                : 'awaiting_confirmation';
            reloadConnections();
            return;
        }

        confirmationCode.value = '';
        provState.value = 'connected';
        reloadConnections();
    } catch {
        provState.value = 'awaiting_confirmation';
        provError.value = 'Could not confirm this Telegram chat. Try again.';
        reloadConnections();
    }
}

function openPersonalReconnect(connection: Connection): void {
    wizardOpen.value = true;
    wizardStep.value = ['awaiting_code', 'awaiting_password'].includes(
        connection.personal_login_step ?? '',
    )
        ? 3
        : 2;
    wizardMethod.value = 'personal';
    wizardMode.value = 'create';
    wizardName.value = connection.name;
    personalPhone.value = '';
    personalCode.value = '';
    personalPassword.value = '';
    personalConnectionId.value = connection.id;
    personalLoginStep.value =
        connection.personal_login_step ?? 'awaiting_phone';
    provisioning.value = {
        connectionId: connection.id,
        code: null,
        deepLink: null,
        expiresAt: null,
        targetLabel: null,
    };
    provState.value = 'waiting';
    provError.value = connection.last_error;
}

usePoll(10_000, { only: ['connections'] });

async function submitPersonalCode(): Promise<void> {
    const connectionId = personalConnectionId.value;
    if (connectionId === null || personalCode.value.trim() === '') return;

    provError.value = null;
    provState.value = 'submitting';
    const code = personalCode.value.trim();

    try {
        const response = await fetch(
            personalLoginCode({ connection: connectionId }).url,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ code }),
            },
        );
        if (!response.ok) {
            const body = (await response.json().catch(() => null)) as {
                message?: string;
            } | null;
            provState.value = 'failed';
            provError.value =
                body?.message ?? 'Could not verify that Telegram code.';
            return;
        }

        personalCode.value = '';
        personalLoginStep.value = 'checking_code';
        reloadConnections();
    } catch {
        provState.value = 'failed';
        provError.value = 'Could not verify that Telegram code. Try again.';
    }
}

async function submitPersonalPassword(): Promise<void> {
    const connectionId = personalConnectionId.value;
    if (connectionId === null || personalPassword.value === '') return;

    provError.value = null;
    provState.value = 'submitting';
    const password = personalPassword.value;

    try {
        const response = await fetch(
            personalLoginPassword({ connection: connectionId }).url,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ password }),
            },
        );
        if (!response.ok) {
            const body = (await response.json().catch(() => null)) as {
                message?: string;
            } | null;
            provState.value = 'failed';
            provError.value =
                body?.message ?? 'Could not verify that password.';
            return;
        }

        personalPassword.value = '';
        personalLoginStep.value = 'checking_password';
        reloadConnections();
    } catch {
        provState.value = 'failed';
        provError.value = 'Could not verify that password. Try again.';
    }
}

const storageChatOpen = ref(false);
const storageChatTarget = ref<Connection | null>(null);
const storageChatForm = useHttp({ chat_ref: '' });
const storageChatError = ref<string | null>(null);
const setupForm = useHttp({
    mode: 'discover' as 'discover' | 'create',
    group_name: '',
});
const currentStorageChat = computed(
    () =>
        props.connections.find(
            (connection) => connection.id === storageChatTarget.value?.id,
        ) ?? storageChatTarget.value,
);
const chatSetupBusy = computed(
    () =>
        ['queued', 'running'].includes(
            currentStorageChat.value?.chat_setup.status ?? '',
        ) || setupForm.processing,
);

function setupStorageChat(mode: 'discover' | 'create'): void {
    if (!storageChatTarget.value) return;
    setupForm.mode = mode;
    setupForm.group_name =
        currentStorageChat.value?.chat_setup.group_name ?? '';
    storageChatError.value = null;
    setupForm.post(
        connectionsRoutes.setupChat({ connection: storageChatTarget.value.id })
            .url,
        {
            onSuccess: () => reloadConnections(),
            onError: (errors) => {
                storageChatError.value =
                    errors.mode ??
                    errors.message ??
                    'Could not start chat setup.';
            },
            onHttpException: (response) => {
                storageChatError.value =
                    (response.data as { message?: string }).message ??
                    'Could not start chat setup.';
                return false;
            },
        },
    );
}

function selectDiscoveredChat(chatId: number): void {
    storageChatForm.chat_ref = String(chatId);
    connectStorageChat();
}

function openStorageChat(connection: Connection): void {
    storageChatTarget.value = connection;
    storageChatForm.reset();
    setupForm.group_name = connection.chat_setup.group_name ?? '';
    storageChatError.value = null;
    storageChatOpen.value = true;
}

function connectStorageChat(): void {
    if (!storageChatTarget.value) return;
    storageChatError.value = null;
    storageChatForm.post(
        connectionsRoutes.verify({ connection: storageChatTarget.value.id })
            .url,
        {
            onSuccess: () => {
                storageChatOpen.value = false;
                toast.info(
                    'Testing file upload, download, and deletion. The result will appear in your connections.',
                );
                reloadConnections();
            },
            onError: (errors) => {
                storageChatError.value =
                    errors.chat_ref ??
                    errors.message ??
                    'Check the chat link and try again.';
            },
            onHttpException: (response) => {
                storageChatError.value =
                    (response.data as { message?: string }).message ??
                    'Could not validate the storage chat.';
                return false;
            },
        },
    );
}

watch(
    () => props.connections,
    (connections) => {
        const active = connections.find(
            (connection) => connection.id === provisioning.value?.connectionId,
        );
        const setup = connections.find(
            (connection) => connection.id === storageChatTarget.value?.id,
        );
        if (wizardOpen.value && active?.method === 'personal') {
            personalConnectionId.value = active.id;
            personalLoginStep.value = active.personal_login_step;
            provError.value = active.last_error;

            if (active.personal_login_step === 'connected') {
                provState.value = 'connected';
            } else if (
                [
                    'requesting_code',
                    'checking_code',
                    'checking_password',
                ].includes(active.personal_login_step ?? '')
            ) {
                provState.value = 'submitting';
            } else {
                provState.value = 'waiting';
            }
        }
        if (wizardOpen.value && active?.method === 'roxi') {
            if (provisioning.value) {
                provisioning.value.expiresAt = active.provision_code_expires_at;
            }

            if (active.provisioning_state !== 'awaiting_confirmation') {
                confirmationCode.value = '';
            }

            if (
                active.provisioning_state === 'confirmed' ||
                active.status === 'connected'
            ) {
                provState.value = 'connected';
                if (provisioning.value) provisioning.value.code = null;
            } else if (
                active.provisioning_state === 'awaiting_confirmation_delivery'
            ) {
                provState.value = 'awaiting_confirmation_delivery';
                if (provisioning.value) provisioning.value.code = null;
            } else if (active.provisioning_state === 'awaiting_confirmation') {
                provState.value = 'awaiting_confirmation';
                if (provisioning.value) provisioning.value.code = null;
            } else if (active.provisioning_state === 'expired') {
                provState.value = 'expired';
                if (provisioning.value) provisioning.value.code = null;
            } else if (active.provisioning_state === 'failed') {
                provState.value = 'failed';
                provError.value = active.last_error ?? 'Provisioning failed.';
                if (provisioning.value) provisioning.value.code = null;
            } else if (active.provisioning_state === 'awaiting_code') {
                provState.value = 'waiting';
            }
        }
        if (storageChatOpen.value && setup?.status === 'connected') {
            storageChatOpen.value = false;
            toast.success('Storage chat connected.');
        }
        if (
            wizardOpen.value &&
            active?.identity_verified &&
            active.status === 'connected'
        ) {
            provState.value = 'connected';
            return;
        }
        if (
            wizardOpen.value &&
            active?.needs_storage_chat &&
            active.identity_verified
        ) {
            wizardOpen.value = false;
            openStorageChat(active);
        }
    },
);
const connectionEditOpen = ref(false);
const connectionEditTarget = ref<Connection | null>(null);
const connectionEditForm = useHttp({ name: '', enabled: true });

function editConnection(connection: Connection): void {
    connectionEditTarget.value = connection;
    connectionEditForm.name = connection.name;
    connectionEditForm.enabled = connection.status !== 'disabled';
    connectionEditOpen.value = true;
}

function saveConnection(): void {
    if (!connectionEditTarget.value) return;
    connectionEditForm.patch(
        connectionsRoutes.update({ connection: connectionEditTarget.value.id })
            .url,
        {
            onSuccess: () => {
                connectionEditOpen.value = false;
                reloadConnections();
            },
        },
    );
}

const deleteConnectionOpen = ref(false);
const deleteConnectionForm = useHttp({ delete_files: false });
const deleteConnectionError = ref<string | null>(null);

function openDeleteConnection(): void {
    if (!connectionEditTarget.value) return;
    deleteConnectionForm.delete_files = false;
    deleteConnectionError.value = null;
    connectionEditOpen.value = false;
    deleteConnectionOpen.value = true;
}

function deleteConnection(): void {
    const connection = connectionEditTarget.value;
    if (!connection) return;

    deleteConnectionForm.delete(
        connectionsRoutes.destroy({ connection: connection.id }).url,
        {
            onSuccess: () => {
                deleteConnectionOpen.value = false;
                connectionEditTarget.value = null;
                toast.success('Connection removal requested.');
                reloadConnections();
            },
            onError: (errors) => {
                deleteConnectionError.value =
                    errors.message ??
                    errors.delete_files ??
                    'Connection could not be deleted.';
            },
            onHttpException: (response) => {
                deleteConnectionError.value =
                    (response.data as { message?: string }).message ??
                    'Connection could not be deleted.';
                return false;
            },
        },
    );
}

// ── Credentials / verify ────────────────────────────────────────────

const credentialsOpen = ref(false);
const credentialsConnection = ref<Connection | null>(null);
const credentialsForm = useHttp({ token: '' });
const credentialsError = ref<string | null>(null);

function openCredentials(connection: Connection): void {
    credentialsConnection.value = connection;
    credentialsForm.reset();
    credentialsError.value = null;
    credentialsOpen.value = true;
}

function saveCredentials(): void {
    const connection = credentialsConnection.value;

    if (!connection) {
        return;
    }

    credentialsForm.patch(
        connectionsRoutes.credentials({ connection: connection.id }).url,
        {
            onSuccess: () => {
                credentialsOpen.value = false;
                toast.success('Credentials updated.');
                reloadConnections();
            },
            onError: (errors) => {
                credentialsError.value =
                    errors.token ?? 'Could not update credentials.';
            },
        },
    );
}

const verifyingId = ref<number | null>(null);

async function verify(connection: Connection): Promise<void> {
    verifyingId.value = connection.id;

    try {
        const response = await fetch(
            connectionsRoutes.verify({ connection: connection.id }).url,
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
            },
        );

        if (response.status === 503) {
            toast.info(
                'The Telegram worker is not running. Verification will be retried automatically.',
            );
        } else if (response.ok) {
            toast.info(
                'Verification queued. The result will appear when the worker finishes.',
            );
        } else {
            toast.error('Verification failed.');
        }
    } catch {
        toast.error('Verification failed.');
    } finally {
        verifyingId.value = null;
        reloadConnections();
    }
}

// ── Realtime events (spec §34) ──────────────────────────────────────

useTelegramEvents({
    onRoxiChatDetected: () => {
        if (wizardOpen.value) {
            provState.value = 'chat_detected';
        }
    },
    onRoxiConnected: (payload) => {
        if (
            provisioning.value?.connectionId !== null &&
            payload.connection_id === provisioning.value?.connectionId
        ) {
            provState.value = 'connected';
            toast.success('Connection verified!');
            reloadConnections();
        }
    },
    onRoxiFailed: (payload) => {
        if (
            provisioning.value?.connectionId !== null &&
            payload.connection_id === provisioning.value?.connectionId
        ) {
            provState.value = 'failed';
            provError.value = payload.error ?? 'Provisioning failed.';
        }
    },
    onConnectionStatus: () => {
        reloadConnections();
        void refreshHealth();
    },
});

// ── Helpers ─────────────────────────────────────────────────────────

function reloadConnections(): void {
    router.reload({ only: ['connections'] });
}

function methodLabel(method: string): string {
    if (method === 'bot') {
        return 'RoxiFile Bot';
    }

    if (method === 'invite') {
        return 'RoxiFile MTProto Invite';
    }

    if (method === 'personal') {
        return 'Personal Telegram';
    }

    return 'RoxiFile MTProto';
}

function connectionStatusLabel(connection: Connection): string {
    if (connection.method !== 'roxi') {
        return connection.status;
    }

    switch (connection.provisioning_state) {
        case 'awaiting_code':
            return 'Pending';
        case 'awaiting_confirmation_delivery':
            return 'Sending confirmation';
        case 'awaiting_confirmation':
            return 'Confirm in RoxiFile';
        case 'confirmed':
            return 'Confirmed';
        case 'expired':
            return 'Expired';
        case 'failed':
            return 'Failed';
        default:
            return connection.status;
    }
}

async function copyCode(code: string | null): Promise<void> {
    if (!code) {
        return;
    }

    await navigator.clipboard.writeText(code);
    toast.success('Code copied');
}

function formatDate(value: string | null): string {
    if (!value) {
        return 'Never';
    }

    return new Date(value).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

const statusVariant = computed(() => {
    return (
        status: string,
    ): 'default' | 'secondary' | 'destructive' | 'outline' => {
        if (status === 'connected') {
            return 'default';
        }

        if (status === 'pending' || status === 'verifying') {
            return 'secondary';
        }

        if (status === 'error') {
            return 'destructive';
        }

        return 'outline';
    };
});

function csrfToken(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
}

const { t } = useLocalization();
</script>

<template>
    <Head :title="t('Storage Chats')" />

    <div class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <Heading
                variant="small"
                :title="t('Storage Chats')"
                :description="t('Connect Telegram groups or channels to store and access your files.')"
            />
            <Button
                size="sm"
                data-test="add-connection-button"
                @click="openWizard"
            >
                <Plus class="size-4" />
                {{ t('Connect Chat') }}
            </Button>
        </div>

        <p class="text-muted-foreground text-sm">
            A storage chat is a Telegram group or channel where your files live.
            Roxi is RoxiFile's Telegram identity; you do not need to log in as
            Roxi. A bot or personal Telegram account is an optional way to
            access storage.
        </p>

        <!-- Worker health strip (spec §41) -->
        <div
            class="bg-card flex flex-wrap items-center gap-4 rounded-xl border p-4"
            data-test="health-strip"
        >
            <div class="flex items-center gap-2 text-sm">
                <component
                    :is="workerHealth?.running ? Wifi : WifiOff"
                    :class="
                        workerHealth?.running
                            ? 'text-green-600'
                            : 'text-muted-foreground'
                    "
                    class="size-4"
                />
                <span class="font-medium">
                    {{
                        workerHealth === null
                            ? 'Checking worker…'
                            : workerHealth.running
                              ? 'Worker running'
                              : 'Worker offline'
                    }}
                </span>
            </div>

            <template v-if="workerHealth?.running">
                <div class="flex items-center gap-2 text-sm">
                    <CheckCircle2
                        :class="
                            workerHealth.roxi_connected
                                ? 'text-green-600'
                                : 'text-muted-foreground'
                        "
                        class="size-4"
                    />
                    {{
                        workerHealth.roxi_connected
                            ? 'Roxi service available'
                            : 'Roxi service unavailable'
                    }}
                </div>
                <div class="text-muted-foreground text-sm">
                    Reconnects: {{ workerHealth.reconnect_count }}
                </div>
                <div
                    v-if="workerHealth.last_error"
                    class="text-destructive flex items-center gap-1 text-sm"
                >
                    <CircleAlert class="size-4" />
                    {{ workerHealth.last_error }}
                </div>
            </template>

            <div class="grow" />

            <Badge
                v-if="(workerHealth?.flagged_files ?? 0) > 0"
                variant="outline"
                class="text-amber-600"
            >
                <TriangleAlert class="size-3" />
                {{ workerHealth?.flagged_files ?? 0 }} file(s) flagged
            </Badge>
        </div>

        <!-- Connections -->
        <div class="space-y-3">
            <div
                v-if="props.connections.length === 0"
                class="border-border/60 bg-muted/30 rounded-2xl border border-dashed p-10 text-center"
            >
                <p class="text-muted-foreground text-sm">
                    No storage chats yet. Connect a chat to choose where your
                    files will be stored.
                </p>
            </div>

            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>{{ t('Storage name') }}</TableHead>
                        <TableHead>{{ t('Method') }}</TableHead>
                        <TableHead>{{ t('Status') }}</TableHead>
                        <TableHead class="hidden md:table-cell">{{ t('Telegram chat') }}</TableHead>
                        <TableHead class="hidden lg:table-cell"
                            >{{ t('Files') }}</TableHead
                        >
                        <TableHead class="hidden lg:table-cell"
                            >{{ t('Last verified') }}</TableHead
                        >
                        <TableHead class="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="connection in props.connections"
                        :key="connection.id"
                        data-test="connection-row"
                    >
                        <TableCell class="font-medium">{{
                            connection.name
                        }}</TableCell>
                        <TableCell>{{
                            methodLabel(connection.method)
                        }}</TableCell>
                        <TableCell>
                            <Badge
                                :variant="statusVariant(connection.status)"
                                >{{ connectionStatusLabel(connection) }}</Badge
                            >
                        </TableCell>
                        <TableCell class="hidden md:table-cell">
                            {{ connection.chat_title ?? '—' }}
                            <span
                                v-if="connection.provision_code_masked"
                                class="text-muted-foreground ml-1 font-mono text-xs"
                                :title="'Provisioning code on record'"
                                >····{{
                                    connection.provision_code_masked
                                }}</span
                            >
                        </TableCell>
                        <TableCell class="hidden lg:table-cell">{{
                            connection.files_count
                        }}</TableCell>
                        <TableCell class="hidden lg:table-cell">
                            {{ formatDate(connection.last_verified_at) }}
                        </TableCell>
                        <TableCell>
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-8"
                                    title="Manage storage chat"
                                    @click="editConnection(connection)"
                                    ><Pencil class="size-4"
                                /></Button>
                                <Button
                                    v-if="
                                        connection.method === 'personal' &&
                                        connection.status !== 'connected' &&
                                        connection.status !== 'connecting' &&
                                        !['revoking', 'revoke_failed'].includes(
                                            connection.personal_login_step ??
                                                '',
                                        )
                                    "
                                    variant="outline"
                                    size="sm"
                                    data-test="personal-reconnect"
                                    @click="openPersonalReconnect(connection)"
                                >
                                    {{
                                        [
                                            'awaiting_code',
                                            'awaiting_password',
                                        ].includes(
                                            connection.personal_login_step ??
                                                '',
                                        )
                                            ? 'Continue sign-in'
                                            : 'Reconnect'
                                    }}
                                </Button>
                                <Button
                                    v-if="
                                        connection.method === 'roxi' &&
                                        connection.status === 'pending' &&
                                        connection.provisioning_state ===
                                            'awaiting_confirmation'
                                    "
                                    variant="outline"
                                    size="sm"
                                    data-test="roxi-confirm"
                                    @click="openRoxiConfirmation(connection)"
                                >
                                    Confirm
                                </Button>
                                <Button
                                    v-if="
                                        connection.needs_storage_chat &&
                                        connection.identity_verified &&
                                        connection.status !== 'reconnecting' &&
                                        connection.status !== 'disabled'
                                    "
                                    variant="outline"
                                    size="sm"
                                    @click="openStorageChat(connection)"
                                >
                                    Connect Chat
                                </Button>
                                <Button
                                    v-if="
                                        (connection.status === 'error' &&
                                            !connection.needs_storage_chat) ||
                                        connection.method === 'bot'
                                    "
                                    variant="ghost"
                                    size="icon"
                                    class="size-8"
                                    :disabled="verifyingId === connection.id"
                                    title="Verify connection"
                                    @click="verify(connection)"
                                >
                                    <ShieldCheck class="size-4" />
                                </Button>
                                <Button
                                    v-if="
                                        (connection.method === 'roxi' ||
                                            connection.method === 'invite') &&
                                        connection.status === 'pending' &&
                                        !connection.identity_verified
                                    "
                                    variant="ghost"
                                    size="icon"
                                    class="size-8"
                                    title="Regenerate provisioning code"
                                    @click="regenerateCode(connection)"
                                >
                                    <RefreshCw class="size-4" />
                                </Button>
                                <Button
                                    v-if="connection.method === 'bot'"
                                    variant="ghost"
                                    size="icon"
                                    class="size-8"
                                    title="Update bot token"
                                    @click="openCredentials(connection)"
                                >
                                    <Link2 class="size-4" />
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <p
                v-for="connection in props.connections.filter(
                    (c) => c.last_error,
                )"
                :key="'e' + connection.id"
                class="text-destructive text-xs"
            >
                {{ connection.name }}: {{ connection.last_error }}
            </p>
        </div>

        <!-- Storage chat wizard (name → access method → provisioning) -->
        <Dialog :open="wizardOpen" @update:open="setWizardOpen">
            <DialogContent class="max-h-[85dvh] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ wizardTitle }}</DialogTitle>
                    <DialogDescription>{{
                        wizardDescription
                    }}</DialogDescription>
                </DialogHeader>

                <!-- Step 1: storage name in RoxiFile -->
                <form
                    v-if="wizardStep === 1"
                    class="space-y-3"
                    @submit.prevent="wizardStep = 2"
                >
                    <div class="space-y-2">
                        <Label for="wizard-name"
                            >Storage name in RoxiFile</Label
                        >
                        <Input
                            id="wizard-name"
                            v-model="wizardName"
                            maxlength="64"
                            autofocus
                            data-test="wizard-name"
                        />
                        <p class="text-muted-foreground text-xs">
                            This is the label in RoxiFile, not the name of your
                            Telegram group or channel.
                        </p>
                    </div>
                    <DialogFooter>
                        <DialogClose as-child>
                            <Button type="button" variant="ghost"
                                >{{ t('Cancel') }}</Button
                            >
                        </DialogClose>
                        <Button
                            type="submit"
                            :disabled="wizardName.trim() === ''"
                            data-test="wizard-next"
                        >
                            {{ t('Continue') }}
                        </Button>
                    </DialogFooter>
                </form>

                <!-- Step 2: Telegram access method -->
                <div v-else-if="wizardStep === 2" class="space-y-3">
                    <div class="grid gap-2">
                        <button
                            type="button"
                            class="border-border flex items-start gap-3 rounded-xl border p-3 text-left transition-colors"
                            :class="
                                wizardMethod === 'bot'
                                    ? 'border-primary bg-primary/5'
                                    : 'hover:bg-muted/40'
                            "
                            data-test="wizard-method-bot"
                            @click="wizardMethod = 'bot'"
                        >
                            <span
                                class="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-lg"
                            >
                                <Bot class="size-4" />
                            </span>
                            <span>
                                <span class="block font-medium"
                                    >RoxiFile Bot</span
                                >
                                <span
                                    class="text-muted-foreground block text-xs"
                                >
                                    Optional: use a bot from @BotFather to
                                    access storage chats it can reach.
                                </span>
                            </span>
                        </button>
                        <button
                            type="button"
                            class="border-border flex items-start gap-3 rounded-xl border p-3 text-left transition-colors"
                            :class="
                                wizardMethod === 'invite'
                                    ? 'border-primary bg-primary/5'
                                    : 'hover:bg-muted/40'
                            "
                            data-test="wizard-method-invite"
                            @click="wizardMethod = 'invite'"
                        >
                            <span
                                class="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-lg"
                            >
                                <UserPlus class="size-4" />
                            </span>
                            <span>
                                <span class="block font-medium"
                                    >RoxiFile MTProto Invite</span
                                >
                                <span
                                    class="text-muted-foreground block text-xs"
                                >
                                    Use Roxi to create a storage group, or
                                    connect your own group or channel. No
                                    Telegram login in RoxiFile is required.
                                </span>
                            </span>
                        </button>
                        <button
                            type="button"
                            class="border-border flex items-start gap-3 rounded-xl border p-3 text-left transition-colors"
                            :class="
                                wizardMethod === 'personal'
                                    ? 'border-primary bg-primary/5'
                                    : 'hover:bg-muted/40'
                            "
                            data-test="wizard-method-personal"
                            @click="wizardMethod = 'personal'"
                        >
                            <span
                                class="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-lg"
                            >
                                <UserPlus class="size-4" />
                            </span>
                            <span>
                                <span class="block font-medium"
                                    >Personal Telegram</span
                                >
                                <span
                                    class="text-muted-foreground block text-xs"
                                >
                                    Optional: sign in to your own Telegram
                                    account to access its storage chat. This
                                    does not sign you in as Roxi.
                                </span>
                            </span>
                        </button>
                    </div>

                    <div v-if="wizardMethod === 'bot'" class="space-y-2">
                        <Label for="wizard-token">Bot token</Label>
                        <Input
                            id="wizard-token"
                            v-model="botToken"
                            type="password"
                            autocomplete="off"
                            data-test="wizard-token"
                        />
                        <p class="text-muted-foreground text-xs">
                            Stored encrypted, never displayed again.
                        </p>
                    </div>
                    <div
                        v-else-if="wizardMethod === 'invite'"
                        class="space-y-2"
                    >
                        <Label for="wizard-target"
                            >Telegram @username or mobile number</Label
                        >
                        <Input
                            id="wizard-target"
                            v-model="inviteTarget"
                            placeholder="@username or +15551234567"
                            maxlength="64"
                            data-test="invite-target"
                        />
                        <p class="text-muted-foreground text-xs">
                            Enter your own Telegram account. Reply to Roxi with
                            the code to verify your identity, then connect your
                            storage chat.
                        </p>
                        <fieldset class="space-y-2">
                            <legend class="text-sm font-medium">
                                Storage chat
                            </legend>
                            <label
                                class="flex items-start gap-2 rounded-lg border p-3 text-sm"
                            >
                                <input
                                    v-model="inviteSetupMode"
                                    type="radio"
                                    value="existing"
                                    name="invite-setup-mode"
                                    class="mt-1"
                                />
                                <span
                                    >Connect my own group or channel<span
                                        class="text-muted-foreground block text-xs"
                                        >Add Roxi as an admin, then use
                                        Discover. You keep ownership.</span
                                    ></span
                                >
                            </label>
                            <label
                                class="flex items-start gap-2 rounded-lg border p-3 text-sm"
                            >
                                <input
                                    v-model="inviteSetupMode"
                                    type="radio"
                                    value="create"
                                    name="invite-setup-mode"
                                    class="mt-1"
                                />
                                <span
                                    >Create a Storage Chat<span
                                        class="text-muted-foreground block text-xs"
                                        >After identity verification, Roxi
                                        creates a private roxifile group and
                                        adds you as an admin. Roxi remains the
                                        owner.</span
                                    ></span
                                >
                            </label>
                        </fieldset>
                        <div
                            v-if="inviteSetupMode === 'create'"
                            class="space-y-2"
                        >
                            <Label for="invite-group-name"
                                >Telegram group name</Label
                            >
                            <Input
                                id="invite-group-name"
                                v-model="inviteGroupName"
                                maxlength="128"
                                placeholder="My RoxiFile Storage"
                                data-test="invite-group-name"
                            />
                            <p class="text-muted-foreground text-xs">
                                RoxiFile uses its icon as the group profile
                                picture.
                            </p>
                        </div>
                    </div>
                    <div
                        v-else-if="wizardMethod === 'personal'"
                        class="space-y-2"
                    >
                        <Label for="personal-phone"
                            >Telegram phone number</Label
                        >
                        <Input
                            id="personal-phone"
                            v-model="personalPhone"
                            type="tel"
                            autocomplete="tel"
                            placeholder="+15551234567"
                            maxlength="16"
                            data-test="personal-phone"
                        />
                        <p class="text-muted-foreground text-xs">
                            Use international format. Sign-in inputs are
                            encrypted while the worker uses them, then cleared.
                        </p>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="wizardStep = 1"
                            >Back</Button
                        >
                        <Button
                            type="button"
                            :disabled="!canStartProvisioning"
                            data-test="wizard-start"
                            @click="startProvisioning"
                        >
                            {{ t('Continue') }}
                        </Button>
                    </DialogFooter>
                </div>

                <!-- Step 3: provisioning -->
                <div v-else class="space-y-4">
                    <!-- Bot: created synchronously with its token -->
                    <template v-if="wizardMethod === 'bot'">
                        <div
                            v-if="provState === 'submitting'"
                            class="flex items-center gap-2 text-sm"
                        >
                            <RefreshCw class="size-4 animate-spin" />
                            Creating bot storage…
                        </div>
                        <div v-else-if="provState === 'done'" class="space-y-4">
                            <div class="flex items-center gap-2 text-sm">
                                <CheckCircle2 class="size-4 text-green-600" />
                                Bot access connected. Message the bot in
                                Telegram to start storing files.
                            </div>
                            <p class="text-muted-foreground text-sm">
                                Telegram only delivers messages the bot can see.
                                In groups, privacy mode can hide ordinary
                                messages; disable it in @BotFather or make the
                                bot a group admin. A webhook or another poller
                                blocks updates; check worker status above if
                                messages are missing.
                            </p>
                            <DialogFooter>
                                <DialogClose as-child>
                                    <Button data-test="wizard-done"
                                        >Done</Button
                                    >
                                </DialogClose>
                            </DialogFooter>
                        </div>
                        <div v-else class="space-y-4">
                            <p
                                class="text-destructive flex items-center gap-1 text-sm"
                            >
                                <CircleAlert class="size-4" />
                                {{
                                    provError ??
                                    'Could not create the bot connection.'
                                }}
                            </p>
                            <DialogFooter>
                                <Button variant="ghost" @click="wizardStep = 2"
                                    >Back</Button
                                >
                            </DialogFooter>
                        </div>
                    </template>

                    <template v-else-if="wizardMethod === 'personal'">
                        <div
                            v-if="
                                personalLoginStep === 'connected' ||
                                provState === 'connected'
                            "
                            class="space-y-4"
                        >
                            <div class="flex items-center gap-2 text-sm">
                                <CheckCircle2 class="size-4 text-green-600" />
                                Personal Telegram connected.
                            </div>
                            <DialogFooter>
                                <DialogClose as-child>
                                    <Button data-test="wizard-done"
                                        >Done</Button
                                    >
                                </DialogClose>
                            </DialogFooter>
                        </div>
                        <div
                            v-else-if="
                                [
                                    'requesting_code',
                                    'checking_code',
                                    'checking_password',
                                ].includes(personalLoginStep ?? '') ||
                                personalLoginStep === null
                            "
                            class="flex items-center gap-2 text-sm"
                        >
                            <RefreshCw class="size-4 animate-spin" />
                            {{
                                personalLoginStep === 'requesting_code'
                                    ? 'Requesting a Telegram code…'
                                    : 'Checking your Telegram sign-in…'
                            }}
                        </div>
                        <form
                            v-else-if="personalLoginStep === 'awaiting_code'"
                            class="space-y-3"
                            @submit.prevent="submitPersonalCode"
                        >
                            <div class="space-y-2">
                                <Label for="personal-code"
                                    >Telegram login code</Label
                                >
                                <Input
                                    id="personal-code"
                                    v-model="personalCode"
                                    inputmode="numeric"
                                    autocomplete="one-time-code"
                                    maxlength="12"
                                    data-test="personal-code"
                                />
                                <p class="text-muted-foreground text-xs">
                                    Enter the latest code Telegram sent to your
                                    account.
                                </p>
                            </div>
                            <p
                                v-if="provError"
                                class="text-destructive text-sm"
                            >
                                {{ provError }}
                            </p>
                            <DialogFooter>
                                <Button
                                    type="submit"
                                    :disabled="personalCode.trim() === ''"
                                    >Verify code</Button
                                >
                            </DialogFooter>
                        </form>
                        <form
                            v-else-if="
                                personalLoginStep === 'awaiting_password'
                            "
                            class="space-y-3"
                            @submit.prevent="submitPersonalPassword"
                        >
                            <div class="space-y-2">
                                <Label for="personal-password"
                                    >Two-step verification password</Label
                                >
                                <Input
                                    id="personal-password"
                                    v-model="personalPassword"
                                    type="password"
                                    autocomplete="current-password"
                                    data-test="personal-password"
                                />
                            </div>
                            <p
                                v-if="provError"
                                class="text-destructive text-sm"
                            >
                                {{ provError }}
                            </p>
                            <DialogFooter>
                                <Button
                                    type="submit"
                                    :disabled="personalPassword === ''"
                                    >Verify password</Button
                                >
                            </DialogFooter>
                        </form>
                        <div v-else class="space-y-3">
                            <p
                                v-if="provError"
                                class="text-destructive flex items-center gap-1 text-sm"
                            >
                                <CircleAlert class="size-4" />{{ provError }}
                            </p>
                            <div class="space-y-2">
                                <Label for="personal-retry-phone"
                                    >Telegram phone number</Label
                                >
                                <Input
                                    id="personal-retry-phone"
                                    v-model="personalPhone"
                                    type="tel"
                                    autocomplete="tel"
                                    placeholder="+15551234567"
                                    maxlength="16"
                                    data-test="personal-phone-retry"
                                />
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    :disabled="
                                        !/^\+[1-9][0-9]{5,14}$/.test(
                                            personalPhone.trim(),
                                        )
                                    "
                                    @click="startProvisioning"
                                    >Request a new code</Button
                                >
                            </DialogFooter>
                        </div>
                    </template>

                    <!-- Roxi uses a two-way code exchange; Invite keeps its existing single-code flow. -->
                    <template v-else>
                        <form
                            v-if="
                                activeRoxiConnection &&
                                (activeRoxiConnection.provisioning_state ===
                                    'awaiting_confirmation' ||
                                    provState === 'awaiting_confirmation')
                            "
                            class="space-y-3"
                            @submit.prevent="submitRoxiConfirmation"
                        >
                            <div class="space-y-2">
                                <Label for="roxi-confirmation-code"
                                    >Roxi confirmation code</Label
                                >
                                <Input
                                    id="roxi-confirmation-code"
                                    v-model="confirmationCode"
                                    autocomplete="one-time-code"
                                    autocapitalize="characters"
                                    maxlength="16"
                                    data-test="roxi-confirmation-code"
                                />
                                <p class="text-muted-foreground text-xs">
                                    Roxi sent a separate code to Telegram. Enter
                                    it here to confirm this connection.
                                </p>
                                <p
                                    v-if="provisioning?.expiresAt"
                                    class="text-muted-foreground text-xs"
                                >
                                    Expires
                                    {{ formatDate(provisioning.expiresAt) }}
                                </p>
                            </div>
                            <p
                                v-if="provError"
                                class="text-destructive text-sm"
                            >
                                {{ provError }}
                            </p>
                            <DialogFooter>
                                <Button
                                    type="submit"
                                    :disabled="
                                        confirmationCode.trim() === '' ||
                                        provState === 'submitting'
                                    "
                                >
                                    {{
                                        provState === 'submitting'
                                            ? 'Confirming…'
                                            : 'Confirm and connect'
                                    }}
                                </Button>
                            </DialogFooter>
                        </form>
                        <div
                            v-else-if="
                                provState === 'awaiting_confirmation_delivery'
                            "
                            role="status"
                            class="flex items-center gap-2 text-sm"
                        >
                            <RefreshCw class="size-4 animate-spin" />
                            Roxi is sending a separate confirmation code to
                            Telegram…
                        </div>
                        <div
                            v-else-if="
                                provState === 'submitting' &&
                                activeRoxiConnection?.provisioning_state ===
                                    'awaiting_confirmation'
                            "
                            role="status"
                            class="flex items-center gap-2 text-sm"
                        >
                            <RefreshCw class="size-4 animate-spin" />
                            Verifying the confirmation code…
                        </div>
                        <div
                            v-else-if="provisioning?.code"
                            class="bg-muted/50 flex flex-col items-center gap-2 rounded-lg p-4"
                        >
                            <p class="text-muted-foreground text-xs">
                                One-time provisioning code
                            </p>
                            <p
                                class="font-mono text-2xl font-bold tracking-widest"
                                data-test="provisioning-code"
                            >
                                {{ provisioning.code }}
                            </p>
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="copyCode(provisioning?.code ?? null)"
                            >
                                <Copy class="size-3.5" />
                                Copy
                            </Button>
                        </div>
                        <div
                            v-else-if="provState === 'submitting'"
                            class="flex items-center gap-2 text-sm"
                        >
                            <RefreshCw class="size-4 animate-spin" />
                            Preparing provisioning code…
                        </div>

                        <p
                            v-if="provisioning?.expiresAt"
                            class="text-muted-foreground text-xs"
                        >
                            Expires {{ formatDate(provisioning.expiresAt) }}
                        </p>

                        <p
                            v-if="
                                wizardMode === 'create' &&
                                provisioning?.targetLabel
                            "
                            class="text-muted-foreground text-xs"
                        >
                            Roxi sent this code to
                            <span class="font-medium">{{
                                provisioning.targetLabel
                            }}</span
                            >. If it doesn't arrive,
                            <a
                                :href="provisioning.deepLink ?? '#'"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="underline"
                            >
                                open Roxi in Telegram
                            </a>
                            and send the code from that chat instead.
                        </p>
                        <p
                            v-else-if="provisioning?.deepLink"
                            class="text-muted-foreground text-xs"
                        >
                            Didn't get it?
                            <a
                                :href="provisioning.deepLink"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="underline"
                            >
                                Open Roxi in Telegram
                            </a>
                            and send the code from that chat.
                        </p>

                        <p
                            v-if="
                                provState === 'failed' ||
                                provState === 'expired'
                            "
                            class="text-destructive flex items-center gap-1 text-sm"
                        >
                            <CircleAlert class="size-4" />
                            {{
                                provError ??
                                (provState === 'expired'
                                    ? 'This provisioning code expired.'
                                    : 'Provisioning failed.')
                            }}
                        </p>

                        <div class="flex items-center gap-2 text-sm">
                            <template v-if="provState === 'waiting'">
                                <RefreshCw class="size-4 animate-spin" />
                                Waiting for the code to be entered in Telegram…
                            </template>
                            <template v-else-if="provState === 'chat_detected'">
                                <CheckCircle2 class="size-4 text-green-600" />
                                Chat detected, finishing up…
                            </template>
                            <template v-else-if="provState === 'connected'">
                                <CheckCircle2 class="size-4 text-green-600" />
                                Confirmed and connected.
                            </template>
                            <template v-else-if="provState === 'expired'">
                                <CircleAlert class="size-4 text-amber-600" />
                                This provisioning code expired.
                            </template>
                        </div>

                        <DialogFooter>
                            <Button
                                v-if="
                                    activeRoxiConnection &&
                                    ['expired', 'failed'].includes(provState)
                                "
                                type="button"
                                variant="outline"
                                data-test="roxi-retry"
                                @click="regenerateCode(activeRoxiConnection)"
                            >
                                Request a new code
                            </Button>
                            <Button
                                v-if="
                                    provState === 'failed' &&
                                    wizardMode === 'create' &&
                                    !activeRoxiConnection
                                "
                                variant="ghost"
                                @click="wizardStep = 2"
                            >
                                Back
                            </Button>
                            <DialogClose
                                v-if="
                                    provState === 'connected' ||
                                    provState === 'failed' ||
                                    provState === 'expired'
                                "
                                as-child
                            >
                                <Button data-test="wizard-done">Done</Button>
                            </DialogClose>
                        </DialogFooter>
                    </template>
                </div>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="storageChatOpen">
            <DialogContent class="max-h-[85dvh] overflow-y-auto sm:max-w-md">
                <form class="space-y-4" @submit.prevent="connectStorageChat">
                    <DialogHeader>
                        <DialogTitle>Connect a Storage Chat</DialogTitle>
                        <DialogDescription>
                            Your Telegram identity is verified. Files will be
                            stored in the group or channel you connect here.
                        </DialogDescription>
                    </DialogHeader>
                    <template
                        v-if="currentStorageChat?.setup_mode === 'create'"
                    >
                        <p class="text-sm">
                            Roxi will create a private group named
                            <strong>{{
                                currentStorageChat.chat_setup.created_title ??
                                currentStorageChat.chat_setup.group_name ??
                                `roxifile-${currentStorageChat.id}`
                            }}</strong>
                            and add you as an administrator. You can rename it
                            in Telegram.
                        </p>
                        <p class="text-muted-foreground text-xs">
                            Roxi owns the group. Your account receives
                            permission to change its name and manage messages.
                        </p>
                        <p
                            v-if="chatSetupBusy"
                            role="status"
                            class="flex items-center gap-2 text-sm"
                        >
                            <RefreshCw class="size-4 animate-spin" />Creating or
                            preparing your group…
                        </p>
                        <template
                            v-if="currentStorageChat.chat_setup.join_link"
                        >
                            <p class="text-sm">
                                Telegram could not add you automatically. Join
                                using your verified Telegram account, then
                                continue below.
                            </p>
                            <a
                                :href="currentStorageChat.chat_setup.join_link"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-primary underline"
                                >Join your storage group</a
                            >
                        </template>
                        <Button
                            type="button"
                            :disabled="
                                chatSetupBusy ||
                                currentStorageChat.status === 'reconnecting'
                            "
                            @click="setupStorageChat('create')"
                        >
                            {{
                                currentStorageChat.chat_setup.join_link
                                    ? 'I joined — continue'
                                    : 'Create Storage Chat'
                            }}
                        </Button>
                        <p
                            v-if="currentStorageChat.status === 'reconnecting'"
                            role="status"
                            class="text-muted-foreground text-sm"
                        >
                            Testing file upload, download, and deletion…
                        </p>
                    </template>
                    <template v-else>
                        <ol class="list-decimal space-y-2 pl-5 text-sm">
                            <li>
                                Create a private Telegram channel (recommended)
                                or group, or choose one you already administer.
                            </li>
                            <li>
                                Add
                                <strong>@{{ props.roxi_username }}</strong> as
                                an administrator. For channels, allow posting
                                messages and deleting messages.
                            </li>
                            <li>
                                Click Discover, then select your group or
                                channel.
                            </li>
                        </ol>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="chatSetupBusy"
                            data-test="discover-chats"
                            @click="setupStorageChat('discover')"
                        >
                            <RefreshCw
                                v-if="chatSetupBusy"
                                class="size-4 animate-spin"
                            />
                            {{
                                chatSetupBusy
                                    ? 'Discovering…'
                                    : 'Discover groups and channels'
                            }}
                        </Button>
                        <div
                            v-if="
                                currentStorageChat?.chat_setup.status ===
                                'complete'
                            "
                            class="space-y-2"
                        >
                            <p
                                v-if="
                                    !currentStorageChat.chat_setup.chats.length
                                "
                                class="text-muted-foreground text-sm"
                            >
                                No eligible chats found. Make sure you and Roxi
                                are administrators, then try Discover again.
                            </p>
                            <Button
                                v-for="chat in currentStorageChat.chat_setup
                                    .chats"
                                :key="chat.id"
                                type="button"
                                variant="outline"
                                class="h-auto w-full justify-start text-left whitespace-normal"
                                :disabled="
                                    storageChatForm.processing || chatSetupBusy
                                "
                                @click="selectDiscoveredChat(chat.id)"
                            >
                                <span
                                    >{{ chat.title
                                    }}<span
                                        class="text-muted-foreground block text-xs"
                                        >{{ chat.type }}</span
                                    ></span
                                >
                            </Button>
                        </div>
                        <div class="space-y-2">
                            <Label for="storage-chat-ref"
                                >Or enter a link manually</Label
                            >
                            <Input
                                id="storage-chat-ref"
                                v-model="storageChatForm.chat_ref"
                                placeholder="https://t.me/+… or @channel"
                                maxlength="255"
                                data-test="storage-chat-ref"
                            />
                            <p class="text-muted-foreground text-xs">
                                Roxi will upload a small test file, download and
                                check it, then delete it before activating
                                storage.
                            </p>
                        </div>
                    </template>
                    <p
                        v-if="
                            currentStorageChat?.chat_setup.error ||
                            currentStorageChat?.last_error
                        "
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{
                            currentStorageChat.chat_setup.error ??
                            currentStorageChat.last_error
                        }}
                    </p>
                    <p
                        v-if="storageChatError"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ storageChatError }}
                    </p>
                    <DialogFooter>
                        <DialogClose as-child
                            ><Button type="button" variant="ghost"
                                >Later</Button
                            ></DialogClose
                        >
                        <Button
                            v-if="currentStorageChat?.setup_mode !== 'create'"
                            type="submit"
                            :disabled="
                                storageChatForm.processing ||
                                chatSetupBusy ||
                                storageChatForm.chat_ref.trim() === ''
                            "
                            data-test="validate-storage-chat"
                        >
                            {{
                                storageChatForm.processing
                                    ? 'Queuing…'
                                    : 'Validate storage chat'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Bot credentials dialog -->
        <Dialog v-model:open="connectionEditOpen">
            <DialogContent class="sm:max-w-sm">
                <form @submit.prevent="saveConnection">
                    <DialogHeader
                        ><DialogTitle>Manage storage chat</DialogTitle
                        ><DialogDescription
                            >Disabling stops new uploads. Existing files remain
                            available.</DialogDescription
                        ></DialogHeader
                    >
                    <div class="my-4 space-y-3">
                        <Label for="connection-edit-name">{{ t('Name') }}</Label>
                        <Input
                            id="connection-edit-name"
                            v-model="connectionEditForm.name"
                            maxlength="64"
                        />
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="connectionEditForm.enabled"
                                type="checkbox"
                            />Accept new uploads</label
                        >
                        <p
                            v-if="connectionEditForm.hasErrors"
                            class="text-destructive text-sm"
                        >
                            {{
                                Object.values(connectionEditForm.errors).join(
                                    ' ',
                                )
                            }}
                        </p>
                    </div>
                    <ConnectionVault
                        v-if="
                            connectionEditTarget &&
                            (contents_encryption_enabled ||
                                connectionEditTarget.encryption_mode !== 'none')
                        "
                        :key="connectionEditTarget.id"
                        :context="{
                            userId: vaultUserId,
                            connectionId: connectionEditTarget.id,
                        }"
                        :mode="connectionEditTarget.encryption_mode"
                        :envelope="connectionEditTarget.vault_envelope"
                        :can-configure="
                            contents_encryption_enabled &&
                            connectionEditTarget.status === 'connected' &&
                            !connectionEditTarget.encryption_locked &&
                            connectionEditTarget.files_count === 0
                        "
                        @configured="
                            connectionEditOpen = false;
                            reloadConnections();
                        "
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="destructive"
                            :disabled="connectionEditForm.processing"
                            @click="openDeleteConnection"
                            >Delete</Button
                        >
                        <Button
                            type="submit"
                            :disabled="connectionEditForm.processing"
                            >{{ t('Save') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="deleteConnectionOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Disconnect chat?</DialogTitle>
                    <DialogDescription>
                        Disconnect "{{ connectionEditTarget?.name }}" from
                        RoxiFile. This does not delete any files or groups from
                        Telegram.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="deleteConnection">
                    <label class="flex items-start gap-3 text-sm">
                        <input
                            v-model="deleteConnectionForm.delete_files"
                            type="checkbox"
                            class="mt-1"
                            data-test="delete-connection-files"
                        />
                        <span>
                            Also permanently remove all file records associated
                            with this storage chat from the RoxiFile database,
                            including Trash ({{
                                connectionEditTarget?.files_count ?? 0
                            }}
                            records). This cannot be undone and the files will
                            no longer be accessible through RoxiFile.
                        </span>
                    </label>
                    <p
                        v-if="deleteConnectionError"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ deleteConnectionError }}
                    </p>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="deleteConnectionOpen = false"
                            >{{ t('Cancel') }}</Button
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="
                                deleteConnectionForm.processing ||
                                ((connectionEditTarget?.files_count ?? 0) > 0 &&
                                    !deleteConnectionForm.delete_files)
                            "
                            data-test="confirm-delete-connection"
                        >
                            {{
                                deleteConnectionForm.processing
                                    ? 'Deleting…'
                                    : 'Disconnect chat'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="credentialsOpen" @update:open="credentialsOpen = $event">
            <DialogContent class="sm:max-w-sm">
                <form @submit.prevent="saveCredentials">
                    <DialogHeader>
                        <DialogTitle>Update bot token</DialogTitle>
                        <DialogDescription>
                            Replace the encrypted token for “{{
                                credentialsConnection?.name
                            }}”.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="mt-4 space-y-2">
                        <Label for="credentials-token">New bot token</Label>
                        <Input
                            id="credentials-token"
                            v-model="credentialsForm.token"
                            type="password"
                            autocomplete="off"
                        />
                        <p
                            v-if="credentialsError"
                            class="text-destructive text-sm"
                        >
                            {{ credentialsError }}
                        </p>
                    </div>
                    <DialogFooter class="mt-4">
                        <DialogClose as-child>
                            <Button type="button" variant="ghost"
                                >{{ t('Cancel') }}</Button
                            >
                        </DialogClose>
                        <Button
                            type="submit"
                            :disabled="credentialsForm.processing"
                            >{{ t('Save') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
