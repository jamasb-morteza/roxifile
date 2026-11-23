<script setup lang="ts">
import { useLocalization } from '@/composables/useLocalization';
import { Head, router, useHttp, usePage } from '@inertiajs/vue3';
import {
    Bot,
    CheckCircle2,
    LogIn,
    Play,
    TriangleAlert,
    Wifi,
    WifiOff,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    settings as settingsRoute,
    workerStatus as workerStatusRoute,
} from '@/routes/telegram';
import { confirm as confirmRoxiLogin } from '@/routes/telegram/roxi';
import * as roxiLoginRoutes from '@/routes/telegram/roxi/login';
import { update as updateConnection } from '@/routes/telegram/connections';
import { start as startWorkerRoute } from '@/routes/telegram/worker';

type Connection = { id: number; name: string; sync_caption_tags: boolean };
type RoxiLoginStep =
    | 'awaiting_phone'
    | 'awaiting_code'
    | 'awaiting_password'
    | 'connected';
type RoxiLoginResponse = { step: RoxiLoginStep };

const props = defineProps<{
    connections: Connection[];
    roxi_username: string | null;
    roxi_login_step: RoxiLoginStep | null;
    roxi_login_open: boolean;
}>();

const captionForm = useHttp({ sync_caption_tags: true });

function toggleCaptionTags(connection: Connection): void {
    captionForm.sync_caption_tags = !connection.sync_caption_tags;
    captionForm.patch(updateConnection({ connection: connection.id }).url, {
        onSuccess: () => router.reload({ only: ['connections'] }),
        onHttpException: () => {
            toast.error('Could not update Telegram caption settings.');
            return false;
        },
    });
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Telegram settings',
                href: settingsRoute(),
            },
        ],
    },
});

// ── Worker health (same feed as the connections page strip) ─────────

type WorkerHealth = {
    running: boolean;
    roxi_connected: boolean;
    pid: number | null;
    started_at: string | null;
    last_heartbeat_at: string | null;
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
    healthTimer = setInterval(() => void refreshHealth(), 10_000);
});

onUnmounted(() => clearInterval(healthTimer));

// ── Start (super admin only; the API enforces it too) ───────────────

const page = usePage();
const isSuperAdmin = computed(
    () => page.props.auth.user.is_super_admin === true,
);

const roxiLoginStep = ref<RoxiLoginStep>(
    props.roxi_login_step ?? 'awaiting_phone',
);
const roxiLoginOpen = ref(
    props.roxi_login_open ||
        roxiLoginStep.value === 'awaiting_code' ||
        roxiLoginStep.value === 'awaiting_password',
);
const roxiPhoneForm = useHttp<{ phone: string }, RoxiLoginResponse>({
    phone: '',
});
const roxiCodeForm = useHttp<{ code: string }, RoxiLoginResponse>({ code: '' });
const roxiPasswordForm = useHttp<{ password: string }, RoxiLoginResponse>({
    password: '',
});
const roxiCancelForm = useHttp<Record<string, never>, RoxiLoginResponse>();
const roxiLoginProcessing = computed(
    () =>
        roxiPhoneForm.processing ||
        roxiCodeForm.processing ||
        roxiPasswordForm.processing ||
        roxiCancelForm.processing,
);

watch(
    () => props.roxi_login_open,
    (open) => {
        if (open) {
            roxiLoginOpen.value = true;
        }
    },
);
watch(
    () => props.roxi_login_step,
    (step) => {
        if (step) {
            roxiLoginStep.value = step;
            roxiLoginOpen.value =
                props.roxi_login_open ||
                step === 'awaiting_code' ||
                step === 'awaiting_password';
        }
    },
);

function openRoxiLogin(): void {
    router.get(confirmRoxiLogin.url());
}

function updateRoxiLoginStep(response: RoxiLoginResponse): void {
    roxiLoginStep.value = response.step;

    if (response.step === 'connected') {
        roxiLoginOpen.value = true;
        toast.success('The configured Roxi Telegram account is connected.');
    }
}

function showRoxiLoginError(response: { data: unknown }): false {
    const body = response.data as { message?: string } | null;
    toast.error(
        body?.message ?? 'Could not sign in to the Roxi Telegram account.',
    );

    return false;
}

function requestRoxiLoginCode(): void {
    roxiPhoneForm.post(roxiLoginRoutes.phone.url(), {
        onSuccess: (response) => {
            roxiPhoneForm.reset('phone');
            updateRoxiLoginStep(response);
        },
        onHttpException: showRoxiLoginError,
    });
}

function submitRoxiLoginCode(): void {
    roxiCodeForm.post(roxiLoginRoutes.code.url(), {
        onSuccess: (response) => {
            roxiCodeForm.reset('code');
            updateRoxiLoginStep(response);
        },
        onHttpException: (response) => {
            roxiCodeForm.reset('code');
            return showRoxiLoginError(response);
        },
    });
}

function submitRoxiLoginPassword(): void {
    roxiPasswordForm.post(roxiLoginRoutes.password.url(), {
        onSuccess: (response) => {
            roxiPasswordForm.reset('password');
            updateRoxiLoginStep(response);
        },
        onHttpException: (response) => {
            roxiPasswordForm.reset('password');
            return showRoxiLoginError(response);
        },
    });
}

function cancelRoxiLogin(): void {
    roxiCancelForm.post(roxiLoginRoutes.cancel.url(), {
        onSuccess: (response) => {
            roxiCodeForm.reset('code');
            roxiPasswordForm.reset('password');
            roxiPhoneForm.reset('phone');
            roxiLoginStep.value = response.step;
            roxiLoginOpen.value = false;
        },
        onHttpException: showRoxiLoginError,
    });
}

const startForm = useHttp<Record<string, never>, { outcome: string }>();

function startWorker(): void {
    startForm.post(startWorkerRoute.url(), {
        headers: { Accept: 'application/json' },
        onSuccess: (response) => {
            toast.success(
                response.outcome === 'already_running'
                    ? 'The Telegram worker is already running.'
                    : 'Start requested — the worker reports in within a few seconds.',
            );
            void refreshHealth();
        },
        onHttpException: (httpResponse) => {
            const body = httpResponse.data as { message?: string } | null;
            toast.error(
                body?.message ?? 'Could not start the Telegram worker.',
            );

            return false;
        },
    });
}

const { t } = useLocalization();
</script>

<template>
    <div class="space-y-8">
        <Head title="Telegram settings" />

        <h1 class="sr-only">{{ t('Telegram settings') }}</h1>

        <Card v-if="isSuperAdmin">
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <LogIn class="size-4" />
                    Roxi Telegram account
                </CardTitle>
                <CardDescription>
                    Sign in to the shared Telegram account used by the Roxi
                    worker.
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4">
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <span class="text-muted-foreground"
                        >Configured account:</span
                    >
                    <code v-if="props.roxi_username"
                        >@{{ props.roxi_username }}</code
                    >
                    <Badge
                        v-if="roxiLoginStep === 'connected'"
                        variant="secondary"
                    >
                        <CheckCircle2 class="size-3" />
                        Signed in
                    </Badge>
                </div>

                <p
                    v-if="!props.roxi_username"
                    class="text-muted-foreground text-sm"
                >
                    Set <code>TELEGRAM_ROXI_USERNAME</code> before signing in.
                </p>

                <div
                    v-else-if="roxiLoginStep === 'connected'"
                    class="space-y-3"
                >
                    <p class="text-muted-foreground text-sm">
                        The session is ready. Start the Telegram worker below to
                        use it.
                    </p>
                </div>

                <div v-else-if="!roxiLoginOpen">
                    <Button
                        data-test="roxi-login-open"
                        :disabled="roxiLoginProcessing"
                        @click="openRoxiLogin"
                    >
                        <LogIn class="size-4" />
                        Connect Telegram account
                    </Button>
                    <p class="text-muted-foreground mt-2 text-xs">
                        RoxiFile will ask you to confirm your password or
                        passkey first.
                    </p>
                </div>

                <form
                    v-else-if="roxiLoginStep === 'awaiting_phone'"
                    class="max-w-md space-y-3"
                    @submit.prevent="requestRoxiLoginCode"
                >
                    <div class="grid gap-2">
                        <Label for="roxi-phone">Telegram phone number</Label>
                        <Input
                            id="roxi-phone"
                            v-model="roxiPhoneForm.phone"
                            type="tel"
                            autocomplete="tel"
                            placeholder="+12345678900"
                            required
                            :disabled="roxiLoginProcessing"
                        />
                        <InputError :message="roxiPhoneForm.errors.phone" />
                    </div>
                    <Button type="submit" :disabled="roxiLoginProcessing">
                        {{
                            roxiPhoneForm.processing
                                ? 'Sending code…'
                                : 'Send Telegram code'
                        }}
                    </Button>
                </form>

                <form
                    v-else-if="roxiLoginStep === 'awaiting_code'"
                    class="max-w-md space-y-3"
                    @submit.prevent="submitRoxiLoginCode"
                >
                    <p class="text-muted-foreground text-sm">
                        Enter the login code Telegram sent to the account’s
                        phone.
                    </p>
                    <div class="grid gap-2">
                        <Label for="roxi-code">Telegram login code</Label>
                        <Input
                            id="roxi-code"
                            v-model="roxiCodeForm.code"
                            autocomplete="one-time-code"
                            inputmode="numeric"
                            required
                            :disabled="roxiLoginProcessing"
                        />
                        <InputError :message="roxiCodeForm.errors.code" />
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button type="submit" :disabled="roxiLoginProcessing">
                            {{
                                roxiCodeForm.processing
                                    ? 'Checking code…'
                                    : 'Continue'
                            }}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="roxiLoginProcessing"
                            @click="cancelRoxiLogin"
                        >
                            Cancel sign-in
                        </Button>
                    </div>
                </form>

                <form
                    v-else-if="roxiLoginStep === 'awaiting_password'"
                    class="max-w-md space-y-3"
                    @submit.prevent="submitRoxiLoginPassword"
                >
                    <p class="text-muted-foreground text-sm">
                        This Telegram account has two-step verification enabled.
                    </p>
                    <div class="grid gap-2">
                        <Label for="roxi-password"
                            >Telegram two-step verification password</Label
                        >
                        <Input
                            id="roxi-password"
                            v-model="roxiPasswordForm.password"
                            type="password"
                            autocomplete="off"
                            required
                            :disabled="roxiLoginProcessing"
                        />
                        <InputError
                            :message="roxiPasswordForm.errors.password"
                        />
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button type="submit" :disabled="roxiLoginProcessing">
                            {{
                                roxiPasswordForm.processing
                                    ? 'Verifying…'
                                    : 'Finish sign-in'
                            }}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="roxiLoginProcessing"
                            @click="cancelRoxiLogin"
                        >
                            Cancel sign-in
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <Card v-if="connections.length">
            <CardHeader>
                <CardTitle class="text-base">Telegram captions</CardTitle>
                <CardDescription
                    >Include file tags in future caption updates for each
                    connection.</CardDescription
                >
            </CardHeader>
            <CardContent class="space-y-3">
                <label
                    v-for="connection in connections"
                    :key="connection.id"
                    class="flex items-center gap-2 text-sm"
                >
                    <input
                        type="checkbox"
                        :checked="connection.sync_caption_tags"
                        :disabled="captionForm.processing"
                        @change="toggleCaptionTags(connection)"
                    />
                    {{ connection.name }}
                </label>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <Bot class="size-4" />
                    Telegram worker
                </CardTitle>
                <CardDescription>
                    The worker stores and retrieves files inside Telegram and
                    verifies connections. Uploads wait while it is offline.
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-5">
                <div
                    data-test="worker-status"
                    class="text-muted-foreground flex flex-wrap items-center gap-3 text-sm"
                >
                    <Badge
                        :variant="
                            workerHealth?.running ? 'default' : 'destructive'
                        "
                    >
                        <component
                            :is="workerHealth?.running ? Wifi : WifiOff"
                            class="size-3"
                        />
                        {{
                            workerHealth === null
                                ? 'Checking worker…'
                                : workerHealth.running
                                  ? 'Worker running'
                                  : 'Worker offline'
                        }}
                    </Badge>
                    <Badge v-if="workerHealth?.running" variant="secondary">
                        {{
                            workerHealth.roxi_connected
                                ? 'Roxi connected'
                                : 'Roxi disconnected'
                        }}
                    </Badge>
                    <span v-if="workerHealth?.running">
                        Reconnects: {{ workerHealth.reconnect_count }}
                    </span>
                    <span v-if="workerHealth?.last_heartbeat_at">
                        Last heartbeat:
                        {{
                            new Date(
                                workerHealth.last_heartbeat_at,
                            ).toLocaleString()
                        }}
                    </span>
                    <Badge
                        v-if="(workerHealth?.flagged_files ?? 0) > 0"
                        variant="outline"
                        class="text-amber-600"
                    >
                        {{ workerHealth?.flagged_files }} file(s) flagged
                    </Badge>
                </div>

                <p
                    v-if="workerHealth?.last_error"
                    class="text-destructive flex items-center gap-1 text-sm"
                >
                    <TriangleAlert class="size-4" />
                    {{ workerHealth.last_error }}
                </p>

                <div class="flex items-center gap-3">
                    <Button
                        v-if="isSuperAdmin"
                        data-test="worker-start"
                        :disabled="
                            startForm.processing ||
                            (workerHealth?.running ?? false)
                        "
                        @click="startWorker"
                    >
                        <Play class="size-4" />
                        {{
                            startForm.processing ? 'Starting…' : 'Start worker'
                        }}
                    </Button>
                    <p v-else class="text-muted-foreground text-sm">
                        Only a super admin can start the worker. Bootstrap one
                        with
                        <code>php artisan user:make-super-admin</code>.
                    </p>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
