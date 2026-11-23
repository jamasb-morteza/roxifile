<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Bot, Palette, ShieldCheck, UserRound } from '@lucide/vue';
import { toUrl } from '@/lib/utils';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { settings as telegramSettings } from '@/routes/telegram';
import type { NavItem } from '@/types';

const sidebarNavItems: (NavItem & { icon: NonNullable<NavItem['icon']> })[] = [
    {
        title: 'Profile',
        href: editProfile(),
        icon: UserRound,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: ShieldCheck,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: Palette,
    },
    {
        title: 'Telegram',
        href: telegramSettings(),
        icon: Bot,
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="mx-auto w-full max-w-7xl">
        <div class="mb-6 space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight">Settings</h1>
            <p class="text-muted-foreground text-sm">
                Manage your profile, security and workspace preferences.
            </p>
        </div>

        <div class="flex flex-col gap-8 lg:flex-row lg:gap-12">
            <aside class="w-full lg:w-56 lg:shrink-0">
                <nav
                    class="flex flex-col space-y-1 space-x-0"
                    aria-label="Settings"
                >
                    <Link
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        :href="item.href"
                        class="text-muted-foreground hover:bg-accent hover:text-accent-foreground data-active:border-primary data-active:text-primary flex items-center gap-2.5 rounded-lg border border-transparent px-3 py-2 text-sm leading-none font-medium transition-colors data-active:border"
                        :data-active="isCurrentOrParentUrl(item.href)"
                    >
                        <component :is="item.icon" class="size-4" />
                        {{ item.title }}
                    </Link>
                </nav>
            </aside>

            <div
                class="border-border/70 hidden self-stretch border-l lg:order-none lg:block"
            />

            <div class="min-w-0 flex-1 lg:max-w-2xl">
                <section class="space-y-8">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
