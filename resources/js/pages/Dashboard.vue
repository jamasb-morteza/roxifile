<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CheckCircle2,
    Files,
    Settings,
    ShieldCheck,
} from '@lucide/vue';
import { computed } from 'vue';
import { useLocalization } from '@/composables/useLocalization';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';
import { index as filesIndex } from '@/routes/files';
import { edit as editProfile } from '@/routes/profile';
import { formatDate } from '@/lib/localization.js';
import type { User } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard().url,
            },
        ],
    },
});

const page = usePage();
const { locale, t } = useLocalization();
const user = computed(() => page.props.auth.user as User);

const greeting = computed(() => {
    const hour = new Date().getHours();
    if (hour < 12) return t('Good morning');
    if (hour < 18) return t('Good afternoon');
    return t('Good evening');
});

const firstName = computed(() => user.value.name.split(' ')[0]);

const accountAge = computed(() => {
    const created = new Date(user.value.created_at);
    return formatDate(created, locale.value, {
        month: 'long',
        year: 'numeric',
    });
});
</script>

<template>
    <Head :title="t('Dashboard')" />

    <!-- Greeting banner -->
    <div class="relative overflow-hidden">
        <div
            class="from-primary/10 via-primary/5 relative overflow-hidden rounded-2xl bg-gradient-to-br to-transparent p-6 md:p-8"
        >
            <div
                class="glow-orb bg-primary/15 absolute -top-16 -right-8 h-48 w-48"
            />
            <div
                class="relative flex flex-wrap items-center justify-between gap-4"
            >
                <div class="space-y-1.5">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ greeting }}, <span dir="auto">{{ firstName }}</span> 👋
                    </h1>
                    <p class="text-muted-foreground text-sm">
                        {{ t('Everything you store here stays private to your account.') }}
                    </p>
                </div>
                <Button v-if="user.email_verified_at" as-child>
                    <Link :href="filesIndex()">
                        {{ t('Go to your files') }}
                        <ArrowRight />
                    </Link>
                </Button>
            </div>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="grid auto-rows-min gap-4 md:grid-cols-2">
        <Card class="gap-0 py-0">
            <CardHeader class="p-6">
                <CardDescription>{{ t('Account') }}</CardDescription>
                <CardTitle class="flex items-center gap-3">
                    <span
                        class="flex size-7 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                    >
                        <ShieldCheck class="size-4" />
                    </span>
                    <span class="text-sm font-medium">
                        {{
                            user.email_verified_at
                                ? t('Email verified')
                                : t('Email unverified')
                        }}
                    </span>
                </CardTitle>
            </CardHeader>
            <div
                class="border-border/60 text-muted-foreground border-t px-6 py-3 text-sm"
            >
                <span class="text-foreground" dir="ltr">{{ user.email }}</span>
            </div>
        </Card>

        <Card class="gap-0 py-0">
            <CardHeader class="p-6">
                <CardDescription>{{ t('Member since') }}</CardDescription>
                <CardTitle class="flex items-center gap-3">
                    <span
                        class="from-primary to-chart-2 flex size-7 items-center justify-center rounded-lg bg-gradient-to-br text-white"
                    >
                        <Files class="size-4" />
                    </span>
                    <span class="text-sm font-medium">{{ accountAge }}</span>
                </CardTitle>
            </CardHeader>
            <div
                class="border-border/60 text-muted-foreground border-t px-6 py-3 text-sm"
            >
                {{ t('Your files live in') }}
                <Link
                    :href="filesIndex()"
                    class="text-foreground underline underline-offset-4"
                    >{{ t('My Files') }}</Link
                >
            </div>
        </Card>
    </div>

    <!-- Quick actions -->
    <div class="grid flex-1 gap-4">
        <Card class="py-0">
            <CardHeader class="p-6 pb-0">
                <CardTitle class="text-base"
                    >{{ t('Get to know your workspace') }}</CardTitle
                >
                <CardDescription>
                    {{ t('A few helpful places to start.') }}
                </CardDescription>
            </CardHeader>
            <div class="grid gap-4 p-6 pt-4 sm:grid-cols-2">
                <Button
                    variant="outline"
                    class="h-auto flex-col items-start gap-2 p-5 text-left"
                    as-child
                >
                    <Link :href="filesIndex()">
                        <span
                            class="bg-primary/10 text-primary flex size-9 items-center justify-center rounded-xl"
                        >
                            <Files class="size-4.5" />
                        </span>
                        <span class="space-y-1">
                            <span class="block font-medium">{{ t('My Files') }}</span>
                            <span
                                class="text-muted-foreground block text-xs font-normal"
                            >
                                {{ t('Upload, organize and tag your files') }}
                            </span>
                        </span>
                    </Link>
                </Button>

                <Button
                    variant="outline"
                    class="h-auto flex-col items-start gap-2 p-5 text-left"
                    as-child
                >
                    <Link :href="editProfile()">
                        <span
                            class="flex size-9 items-center justify-center rounded-xl bg-sky-500/10 text-sky-600 dark:text-sky-400"
                        >
                            <Settings class="size-4.5" />
                        </span>
                        <span class="space-y-1">
                            <span class="block font-medium"
                                >{{ t('Profile settings') }}</span
                            >
                            <span
                                class="text-muted-foreground block text-xs font-normal"
                            >
                                {{ t('Update your name, email & preferences') }}
                            </span>
                        </span>
                    </Link>
                </Button>
            </div>

            <div
                class="border-border/60 flex flex-1 items-center justify-center rounded-b-xl border-t border-dashed p-8"
            >
                <p
                    class="text-muted-foreground flex items-center gap-2 text-sm"
                >
                    <CheckCircle2 class="text-primary size-4" />
                    {{ t('Your account is all set — file uploads are one click away.') }}
                </p>
            </div>
        </Card>
    </div>
</template>
