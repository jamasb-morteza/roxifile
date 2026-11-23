<script setup lang="ts">
import { useLocalization } from '@/composables/useLocalization';
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { MailWarning, Send } from '@lucide/vue';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Domains/Identity/Http/Controllers/Settings/ProfileController';
import { useInitials } from '@/composables/useInitials';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const { getInitials } = useInitials();

const { t } = useLocalization();
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">{{ t('Profile settings') }}</h1>

    <div class="flex flex-col space-y-8">
        <!-- Identity header -->
        <div class="flex items-center gap-4">
            <Avatar class="size-16">
                <AvatarImage
                    v-if="user.avatar"
                    :src="user.avatar"
                    :alt="user.name"
                />
                <AvatarFallback class="text-lg font-medium">
                    {{ getInitials(user.name) }}
                </AvatarFallback>
            </Avatar>
            <div class="min-w-0 space-y-1.5">
                <p class="truncate font-semibold">
                    {{ user.name }}
                </p>
                <p class="text-muted-foreground truncate text-sm">
                    {{ user.email }}
                </p>
                <Badge
                    v-if="user.email_verified_at"
                    variant="secondary"
                    class="gap-1"
                >
                    <span class="size-1.5 rounded-full bg-emerald-500" />
                    {{ t('Verified') }}
                </Badge>
                <Badge v-else variant="secondary" class="gap-1">
                    <MailWarning class="size-3" />
                    {{ t('Unverified email') }}
                </Badge>
            </div>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">{{ t('Personal information') }}</CardTitle>
                <CardDescription>
                    {{ t('Update your name and email address') }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="ProfileController.update.form()"
                    class="space-y-6"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-2">
                        <Label for="name">{{ t('Name') }}</Label>
                        <Input
                            id="name"
                            class="w-full"
                            name="name"
                            :default-value="user.name"
                            required
                            autocomplete="name"
                            placeholder="Full name"
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">{{ t('Email address') }}</Label>
                        <Input
                            id="email"
                            type="email"
                            class="w-full"
                            name="email"
                            :default-value="user.email"
                            required
                            autocomplete="username"
                            placeholder="Email address"
                        />
                        <InputError :message="errors.email" />
                    </div>

                    <div
                        v-if="
                            page.props.mustVerifyEmail &&
                            !user.email_verified_at
                        "
                        class="border-border bg-muted/40 flex items-start gap-3 rounded-lg border p-3"
                    >
                        <MailWarning
                            class="mt-0.5 size-4 shrink-0 text-amber-500"
                        />
                        <div class="text-muted-foreground text-sm">
                            Your email address is unverified.
                            <Link
                                :href="send()"
                                as="button"
                                class="text-primary underline-offset-4 hover:underline"
                            >
                                <span class="inline-flex items-center gap-1">
                                    Re-send the verification email
                                    <Send class="size-3" />
                                </span>
                            </Link>

                            <div
                                v-if="
                                    page.props.status ===
                                    'verification-link-sent'
                                "
                                class="mt-1 font-medium text-emerald-600 dark:text-emerald-400"
                            >
                                A new verification link has been sent to your
                                email address.
                            </div>
                        </div>
                    </div>

                    <Button
                        :disabled="processing"
                        data-test="update-profile-button"
                    >
                        Save changes
                    </Button>
                </Form>
            </CardContent>
        </Card>
    </div>

    <DeleteUser />
</template>
