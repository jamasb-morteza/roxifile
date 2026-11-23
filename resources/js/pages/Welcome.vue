<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Check,
    ChevronRight,
    CloudUpload,
    FileImage,
    FileText,
    FolderClosed,
    HardDrive,
    Images,
    LockKeyhole,
    Music2,
    Play,
    ShieldCheck,
    Sparkles,
    WifiOff,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import LanguageSelector from '@/components/LanguageSelector.vue';
import { Button } from '@/components/ui/button';
import { useLocalization } from '@/composables/useLocalization';
import { dashboard, login, register } from '@/routes';

const page = usePage();
const { t, direction } = useLocalization();
const isAuthenticated = computed(() => Boolean(page.props.auth.user));
const primaryDestination = computed(() =>
    isAuthenticated.value ? dashboard() : register(),
);

const fileRows = [
    { name: 'project-notes.pdf', icon: FileText, tone: 'violet', size: 'PDF' },
    { name: 'summer-photo.jpg', icon: FileImage, tone: 'amber', size: 'JPG' },
    { name: 'favorite-track.mp3', icon: Music2, tone: 'mint', size: 'MP3' },
] as const;

const capabilities = computed(() => [
    {
        icon: FolderClosed,
        title: t('Put every file in its place'),
        description: t(
            'Keep folders, files and tags together in a space that is easy to navigate.',
        ),
        tone: 'primary',
    },
    {
        icon: HardDrive,
        title: t('Connect your storage chats'),
        description: t(
            'Choose your Telegram storage chats and manage files from one familiar interface.',
        ),
        tone: 'amber',
    },
    {
        icon: Play,
        title: t('Enjoy your media'),
        description: t(
            'Preview supported documents and play audio or video without leaving your library.',
        ),
        tone: 'mint',
    },
    {
        icon: WifiOff,
        title: t('Make room for offline access'),
        description: t(
            'Save browser copies of selected files for when you are away from a connection.',
        ),
        tone: 'sky',
    },
]);

const steps = computed(() => [
    {
        number: '01',
        title: t('Create your space'),
        description: t(
            'Create an account or sign in to pick up where you left off.',
        ),
    },
    {
        number: '02',
        title: t('Connect a storage chat'),
        description: t(
            'Select a Telegram chat as the destination for your files.',
        ),
    },
    {
        number: '03',
        title: t('Upload, find and enjoy'),
        description: t(
            'Organize your files, search your library and open your media.',
        ),
    },
]);
</script>

<template>
    <Head :title="t('Welcome')" />

    <div
        :dir="direction"
        class="bg-background text-foreground min-h-dvh overflow-x-clip"
    >
        <a
            href="#main-content"
            class="bg-primary text-primary-foreground focus-visible:ring-ring sr-only z-50 rounded-lg px-4 py-3 focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus-visible:ring-2"
        >
            {{ t('Skip to content') }}
        </a>

        <header
            class="border-border/70 bg-background/95 sticky top-0 z-40 border-b backdrop-blur-lg"
        >
            <nav
                class="mx-auto flex h-[4.75rem] w-full max-w-7xl items-center justify-between gap-3 px-4 sm:px-6 lg:px-8"
                :aria-label="t('Main navigation')"
            >
                <Link
                    :href="'/'"
                    class="flex shrink-0 items-center"
                    aria-label="RoxiFile"
                >
                    <AppLogo />
                </Link>

                <div
                    class="hidden items-center gap-7 text-sm font-medium sm:flex"
                >
                    <a
                        class="text-muted-foreground hover:text-foreground focus-visible:ring-ring rounded-md transition-colors focus-visible:ring-2"
                        href="#features"
                    >
                        {{ t('Features') }}
                    </a>
                    <a
                        class="text-muted-foreground hover:text-foreground focus-visible:ring-ring rounded-md transition-colors focus-visible:ring-2"
                        href="#how-it-works"
                    >
                        {{ t('How it works') }}
                    </a>
                </div>

                <div class="flex shrink-0 items-center gap-1.5 sm:gap-3">
                    <LanguageSelector />
                    <Button
                        v-if="!isAuthenticated"
                        variant="ghost"
                        class="hidden sm:inline-flex"
                        as-child
                    >
                        <Link :href="login()">{{ t('Log in') }}</Link>
                    </Button>
                    <Button as-child class="h-11 px-3 sm:px-5">
                        <Link :href="primaryDestination">
                            {{
                                isAuthenticated
                                    ? t('Open dashboard')
                                    : t('Get started')
                            }}
                            <ArrowRight
                                aria-hidden="true"
                                class="rtl-mirror ms-1 size-4"
                            />
                        </Link>
                    </Button>
                </div>
            </nav>
        </header>

        <main id="main-content">
            <!-- The product comes first: clear value, direct next step, genuine UI-shaped illustration. -->
            <section class="relative isolate overflow-hidden">
                <div
                    aria-hidden="true"
                    class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(ellipse_at_78%_18%,color-mix(in_oklab,var(--primary)_12%,transparent),transparent_50%)] dark:opacity-70"
                />
                <div
                    class="mx-auto grid w-full max-w-7xl items-center gap-12 px-4 pt-16 pb-20 sm:px-6 md:pt-20 lg:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)] lg:gap-16 lg:px-8 lg:pt-28 lg:pb-28"
                >
                    <div class="max-w-xl text-start">
                        <div
                            class="border-primary/15 bg-primary/5 text-primary mb-7 inline-flex items-center gap-2 rounded-full border px-3.5 py-2 text-sm font-semibold"
                        >
                            <Sparkles aria-hidden="true" class="size-4" />
                            {{ t('A simpler home for your files') }}
                        </div>
                        <h1
                            class="text-4xl leading-[1.15] font-bold tracking-tight text-balance sm:text-5xl lg:text-[3.65rem]"
                        >
                            {{ t('Your files deserve') }}
                            <span class="text-primary block">{{
                                t('a better place.')
                            }}</span>
                        </h1>
                        <p
                            class="text-muted-foreground mt-7 max-w-[34rem] text-base leading-8 sm:text-lg"
                        >
                            {{
                                t(
                                    'Bring your folders, documents and media together. RoxiFile helps you stay organized using Telegram chats as your storage destination.',
                                )
                            }}
                        </p>
                        <div class="mt-9 flex flex-wrap items-center gap-3">
                            <Button
                                as-child
                                size="lg"
                                class="shadow-primary/15 h-12 rounded-xl px-6 text-base shadow-md"
                            >
                                <Link :href="primaryDestination">
                                    {{
                                        isAuthenticated
                                            ? t('Open dashboard')
                                            : t('Create your account')
                                    }}
                                    <ArrowRight
                                        aria-hidden="true"
                                        class="rtl-mirror ms-1 size-4"
                                    />
                                </Link>
                            </Button>
                            <Button
                                as-child
                                size="lg"
                                variant="outline"
                                class="h-12 rounded-xl px-5 text-base"
                            >
                                <a href="#features">{{
                                    t('Explore features')
                                }}</a>
                            </Button>
                        </div>
                        <div
                            class="text-muted-foreground mt-8 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm"
                        >
                            <span class="inline-flex items-center gap-2">
                                <Check
                                    aria-hidden="true"
                                    class="text-primary size-4"
                                />{{ t('Organize files and folders') }}
                            </span>
                            <span class="inline-flex items-center gap-2">
                                <Check
                                    aria-hidden="true"
                                    class="text-primary size-4"
                                />{{ t('Preview and play media') }}
                            </span>
                        </div>
                    </div>

                    <!-- Illustrative preview: deliberately static, not real account data. -->
                    <div
                        class="relative mx-auto w-full max-w-[37rem]"
                        role="img"
                        :aria-label="
                            t('Illustrative RoxiFile file manager preview')
                        "
                    >
                        <div
                            aria-hidden="true"
                            class="bg-primary/10 absolute -inset-5 rounded-[2.75rem] blur-2xl"
                        />
                        <div
                            class="border-border bg-card relative overflow-hidden rounded-[1.6rem] border shadow-[0_25px_80px_-35px_rgba(54,45,115,0.38)] dark:shadow-none"
                        >
                            <div
                                class="border-border bg-muted/45 flex items-center justify-between gap-3 border-b px-5 py-4"
                            >
                                <div class="flex items-center gap-2.5">
                                    <div
                                        class="bg-primary/10 text-primary grid size-9 place-items-center rounded-xl"
                                    >
                                        <FolderClosed
                                            aria-hidden="true"
                                            class="size-5"
                                        />
                                    </div>
                                    <div class="min-w-0">
                                        <p
                                            class="text-foreground text-sm font-semibold"
                                        >
                                            RoxiFile
                                        </p>
                                        <p
                                            class="text-muted-foreground text-[11px]"
                                        >
                                            {{ t('Workspace preview') }}
                                        </p>
                                    </div>
                                </div>
                                <div
                                    class="border-border bg-background flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs"
                                >
                                    <span
                                        class="size-1.5 rounded-full bg-emerald-500"
                                    />
                                    {{ t('My files') }}
                                </div>
                            </div>
                            <div
                                class="grid min-h-80 grid-cols-[5.5rem_minmax(0,1fr)] sm:grid-cols-[9rem_minmax(0,1fr)]"
                            >
                                <aside
                                    class="border-border bg-muted/25 flex flex-col gap-1 border-e px-2 py-5 sm:px-3"
                                >
                                    <div
                                        class="bg-primary/10 text-primary flex items-center gap-2 rounded-lg px-2 py-2.5 text-xs font-semibold sm:px-3"
                                    >
                                        <FolderClosed
                                            aria-hidden="true"
                                            class="size-4 shrink-0"
                                        />
                                        <span class="truncate">{{
                                            t('All files')
                                        }}</span>
                                    </div>
                                    <div
                                        class="text-muted-foreground flex items-center gap-2 rounded-lg px-2 py-2.5 text-xs sm:px-3"
                                    >
                                        <Images
                                            aria-hidden="true"
                                            class="size-4 shrink-0"
                                        />
                                        <span class="truncate">{{
                                            t('Media Player')
                                        }}</span>
                                    </div>
                                    <div
                                        class="text-muted-foreground flex items-center gap-2 rounded-lg px-2 py-2.5 text-xs sm:px-3"
                                    >
                                        <HardDrive
                                            aria-hidden="true"
                                            class="size-4 shrink-0"
                                        />
                                        <span class="truncate">{{
                                            t('Storage Chats')
                                        }}</span>
                                    </div>
                                </aside>
                                <div class="min-w-0 px-3 py-5 sm:px-5">
                                    <div
                                        class="mb-5 flex items-center justify-between gap-2"
                                    >
                                        <div class="min-w-0">
                                            <p
                                                class="text-foreground text-sm font-bold"
                                            >
                                                {{ t('My files') }}
                                            </p>
                                            <p
                                                class="text-muted-foreground mt-1 text-xs"
                                            >
                                                {{
                                                    t('Everything in one place')
                                                }}
                                            </p>
                                        </div>
                                        <div
                                            class="bg-primary text-primary-foreground flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-2 text-xs font-medium sm:px-3"
                                        >
                                            <CloudUpload
                                                aria-hidden="true"
                                                class="size-3.5"
                                            />
                                            <span class="hidden sm:inline">{{
                                                t('Upload')
                                            }}</span>
                                        </div>
                                    </div>
                                    <p
                                        class="text-muted-foreground mb-2 text-[11px] font-semibold tracking-wide uppercase"
                                    >
                                        {{ t('Recent files') }}
                                    </p>
                                    <div class="divide-border divide-y">
                                        <div
                                            v-for="file in fileRows"
                                            :key="file.name"
                                            class="flex min-w-0 items-center gap-3 py-3"
                                        >
                                            <div
                                                class="grid size-9 shrink-0 place-items-center rounded-xl"
                                                :class="
                                                    file.tone === 'violet'
                                                        ? 'bg-primary/10 text-primary'
                                                        : file.tone === 'amber'
                                                          ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                                                          : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                                "
                                            >
                                                <component
                                                    :is="file.icon"
                                                    aria-hidden="true"
                                                    class="size-4.5"
                                                />
                                            </div>
                                            <span
                                                dir="ltr"
                                                class="text-foreground min-w-0 flex-1 truncate text-start text-xs font-medium sm:text-sm"
                                                >{{ file.name }}</span
                                            >
                                            <span
                                                class="text-muted-foreground text-[10px]"
                                                >{{ file.size }}</span
                                            >
                                        </div>
                                    </div>
                                    <div
                                        class="border-primary/15 bg-primary/5 mt-5 flex items-center gap-2 rounded-xl border px-3 py-3"
                                    >
                                        <ShieldCheck
                                            aria-hidden="true"
                                            class="text-primary size-4 shrink-0"
                                        />
                                        <p class="text-foreground/80 text-xs">
                                            {{
                                                t('Your storage, your choice.')
                                            }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div
                            class="border-border bg-card relative mx-auto mt-4 flex w-fit items-center gap-2 rounded-full border px-4 py-2 text-xs shadow-sm"
                        >
                            <LockKeyhole
                                aria-hidden="true"
                                class="text-primary size-3.5"
                            />
                            {{ t('Built around your privacy') }}
                        </div>
                    </div>
                </div>
            </section>

            <section
                id="features"
                class="border-border bg-muted/35 border-y py-20 sm:py-24"
                :aria-label="t('Features')"
            >
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="mb-10 max-w-2xl text-start">
                        <p
                            class="text-primary text-sm font-semibold tracking-wide"
                        >
                            {{ t('MADE FOR YOUR FILES') }}
                        </p>
                        <h2
                            class="mt-3 text-3xl font-bold tracking-tight text-balance sm:text-4xl"
                        >
                            {{ t('Everything you need, within reach.') }}
                        </h2>
                        <p
                            class="text-muted-foreground mt-4 text-base leading-7"
                        >
                            {{
                                t(
                                    'Less time hunting through chats. More time using what you have saved.',
                                )
                            }}
                        </p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <article
                            v-for="feature in capabilities"
                            :key="feature.title"
                            class="border-border bg-card hover:border-primary/35 rounded-2xl border p-6 transition-colors duration-200"
                        >
                            <div
                                class="mb-5 grid size-12 place-items-center rounded-2xl"
                                :class="
                                    feature.tone === 'amber'
                                        ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                                        : feature.tone === 'mint'
                                          ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                          : feature.tone === 'sky'
                                            ? 'bg-sky-500/10 text-sky-600 dark:text-sky-400'
                                            : 'bg-primary/10 text-primary'
                                "
                            >
                                <component
                                    :is="feature.icon"
                                    aria-hidden="true"
                                    class="size-6"
                                />
                            </div>
                            <h3 class="text-lg font-semibold">
                                {{ feature.title }}
                            </h3>
                            <p
                                class="text-muted-foreground mt-3 text-sm leading-7"
                            >
                                {{ feature.description }}
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <section
                id="how-it-works"
                class="mx-auto max-w-7xl px-4 py-20 sm:px-6 sm:py-24 lg:px-8"
            >
                <div
                    class="flex flex-col justify-between gap-5 sm:flex-row sm:items-end"
                >
                    <div class="max-w-xl text-start">
                        <p
                            class="text-primary text-sm font-semibold tracking-wide"
                        >
                            {{ t('GET STARTED') }}
                        </p>
                        <h2
                            class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl"
                        >
                            {{ t('Three simple steps. Your own space.') }}
                        </h2>
                    </div>
                    <p
                        class="text-muted-foreground max-w-sm text-start text-sm leading-7"
                    >
                        {{
                            t(
                                'Start with an account, connect your storage and take control of your files.',
                            )
                        }}
                    </p>
                </div>
                <ol class="mt-12 grid gap-4 md:grid-cols-3">
                    <li
                        v-for="step in steps"
                        :key="step.number"
                        class="border-border relative rounded-2xl border px-6 py-7"
                    >
                        <div
                            class="text-primary/45 mb-6 text-3xl font-bold tracking-tight tabular-nums"
                        >
                            {{ step.number }}
                        </div>
                        <h3 class="text-lg font-semibold">{{ step.title }}</h3>
                        <p class="text-muted-foreground mt-3 text-sm leading-7">
                            {{ step.description }}
                        </p>
                        <ChevronRight
                            aria-hidden="true"
                            class="text-muted-foreground/40 rtl-mirror absolute end-5 top-7 size-5"
                        />
                    </li>
                </ol>
            </section>

            <section
                class="mx-auto max-w-7xl px-4 pb-20 sm:px-6 sm:pb-24 lg:px-8"
            >
                <div
                    class="bg-primary text-primary-foreground relative overflow-hidden rounded-[2rem] px-6 py-12 sm:px-10 sm:py-14 lg:px-16"
                >
                    <div
                        aria-hidden="true"
                        class="pointer-events-none absolute -end-14 -top-24 size-72 rounded-full border border-white/15"
                    />
                    <div
                        aria-hidden="true"
                        class="pointer-events-none absolute -end-7 -top-16 size-56 rounded-full border border-white/20"
                    />
                    <div
                        class="relative flex flex-col gap-7 md:flex-row md:items-center md:justify-between"
                    >
                        <div class="max-w-xl text-start">
                            <h2
                                class="text-3xl font-bold tracking-tight text-balance sm:text-4xl"
                            >
                                {{ t('Ready to make space for what matters?') }}
                            </h2>
                            <p
                                class="text-primary-foreground/85 mt-4 text-base leading-8"
                            >
                                {{
                                    t(
                                        'Bring your files together in RoxiFile. Your next upload is a good place to start.',
                                    )
                                }}
                            </p>
                        </div>
                        <Button
                            size="lg"
                            variant="secondary"
                            as-child
                            class="h-12 shrink-0 rounded-xl px-6 font-semibold"
                        >
                            <Link :href="primaryDestination">
                                {{
                                    isAuthenticated
                                        ? t('Open dashboard')
                                        : t('Get started')
                                }}
                                <ArrowRight
                                    aria-hidden="true"
                                    class="rtl-mirror ms-1 size-4"
                                />
                            </Link>
                        </Button>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-border border-t">
            <div
                class="text-muted-foreground mx-auto flex max-w-7xl flex-col justify-between gap-4 px-4 py-7 text-sm sm:flex-row sm:items-center sm:px-6 lg:px-8"
            >
                <p>
                    © {{ new Date().getFullYear() }} RoxiFile.
                    {{ t('All rights reserved.') }}
                </p>
                <div class="flex items-center gap-5">
                    <a
                        href="#features"
                        class="hover:text-foreground transition-colors"
                        >{{ t('Features') }}</a
                    >
                    <a
                        href="#how-it-works"
                        class="hover:text-foreground transition-colors"
                        >{{ t('How it works') }}</a
                    >
                </div>
            </div>
        </footer>
    </div>
</template>
