<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import { useLocalization } from '@/composables/useLocalization';

const page = usePage();
const appName = computed(() => page.props.name);
const { t } = useLocalization();

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="bg-background flex min-h-svh">
        <!-- Brand panel -->
        <aside
            class="relative hidden w-[46%] flex-col justify-between overflow-hidden p-10 text-white lg:flex"
            style="
                background: linear-gradient(
                    135deg,
                    hsl(243 75% 52%) 0%,
                    hsl(262 70% 55%) 100%
                );
            "
        >
            <div
                class="bg-grid pointer-events-none absolute inset-0 opacity-15 invert"
            />
            <div
                class="glow-orb absolute -top-20 -left-20 h-72 w-72 bg-white/20"
            />
            <div
                class="glow-orb absolute right-0 bottom-0 h-80 w-80 bg-fuchsia-400/25"
            />

            <div class="relative">
                <img
                    src="/roxifile_logo.png"
                    alt="RoxiFile"
                    width="2172"
                    height="724"
                    class="h-20 w-60 rounded-xl bg-white object-contain"
                />
            </div>

            <div class="relative space-y-6">
                <h2
                    class="max-w-md text-3xl leading-snug font-semibold text-balance"
                >
                    {{ t('Your files, beautifully organized and always within reach.') }}
                </h2>
                <p class="max-w-md text-sm/relaxed text-white/75">
                    {{ t('Secure cloud storage with private-by-default files and passkey auth — built for individuals and pros.') }}
                </p>
                <ul class="space-y-3 text-sm">
                    <li class="flex items-center gap-3">
                        <span
                            class="flex size-5 items-center justify-center rounded-full bg-white/15"
                        >
                            <Check class="size-3" />
                        </span>
                        {{ t('End-to-end secure file sharing') }}
                    </li>
                    <li class="flex items-center gap-3">
                        <span
                            class="flex size-5 items-center justify-center rounded-full bg-white/15"
                        >
                            <Check class="size-3" />
                        </span>
                        {{ t('Private-by-default file storage') }}
                    </li>
                    <li class="flex items-center gap-3">
                        <span
                            class="flex size-5 items-center justify-center rounded-full bg-white/15"
                        >
                            <Check class="size-3" />
                        </span>
                        {{ t('Passkeys, 2FA & recovery codes') }}
                    </li>
                </ul>
            </div>

            <p class="relative text-xs text-white/60">
                © {{ new Date().getFullYear() }} {{ appName }}. {{ t('All rights reserved.') }}
            </p>
        </aside>

        <!-- Form panel -->
        <main
            class="relative flex min-h-svh flex-1 flex-col items-center justify-center gap-6 p-6 md:p-10"
        >
            <div
                class="bg-grid pointer-events-none absolute inset-0 opacity-60 lg:hidden"
            />
            <div
                class="glow-orb bg-primary/15 pointer-events-none absolute -top-32 left-1/2 h-72 w-72 -translate-x-1/2 lg:hidden"
            />

            <div class="relative w-full max-w-sm">
                <div class="mb-8 flex justify-center lg:hidden">
                    <img
                        src="/roxifile_logo.png"
                        alt="RoxiFile"
                        width="2172"
                        height="724"
                        class="h-16 w-48 rounded-xl bg-white object-contain"
                    />
                </div>

                <div v-if="title || description" class="space-y-2 text-center">
                    <h1
                        v-if="title"
                        class="text-xl font-semibold tracking-tight"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="text-muted-foreground text-sm text-pretty"
                    >
                        {{ description }}
                    </p>
                </div>

                <div class="mt-8">
                    <slot />
                </div>
            </div>

            <Link
                :href="'/'"
                class="text-muted-foreground relative z-10 text-sm hover:underline"
            >
                {{ t('Back to home') }}
            </Link>
        </main>
    </div>
</template>
