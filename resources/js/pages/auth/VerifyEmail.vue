<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useLocalization } from '@/composables/useLocalization';
import { logoutBrowserCopies } from '@/lib/browserDownloads';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

const { t } = useLocalization();

defineOptions({
    layout: {
        title: 'Email verification',
        description:
            'Please verify your email address by clicking on the link we just emailed to you.',
    },
});

const logoutError = ref('');
const page = usePage();
async function signOut(): Promise<void> {
    try {
        await logoutBrowserCopies(Number(page.props.auth.user.id));
        router.flushAll();
        router.post(logout.url());
    } catch {
        logoutError.value =
            t('Browser copies could not be cleared. Retry logout.');
    }
}
defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head :title="t('Email verification')" />

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-2.5 text-center text-sm font-medium text-emerald-600 dark:text-emerald-400"
    >
        {{ t('A new verification link has been sent to the email address you provided during registration.') }}
    </div>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button :disabled="processing" variant="secondary">
            <Spinner v-if="processing" />
            {{ t('Resend verification email') }}
        </Button>

        <p v-if="logoutError" role="alert">{{ logoutError }}</p>
        <button
            type="button"
            @click="signOut"
            class="mx-auto block text-sm underline"
        >
            {{ t('Log out') }}
        </button>
    </Form>
</template>
