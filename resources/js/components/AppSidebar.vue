<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    HardDrive,
    BookOpen,
    Files,
    Folder,
    Trash2,
    FolderGit2,
    LayoutGrid,
    Music2,
    Tags,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarGroupContent,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as mediaIndex } from '@/routes/media';
import { index as filesIndex } from '@/routes/files';
import { index as tagsIndex } from '@/routes/tags';
import { connections as telegramConnections } from '@/routes/telegram';
import { useLocalization } from '@/composables/useLocalization';
import type { NavItem } from '@/types';

const page = usePage();
const { t, direction } = useLocalization();
const isFileManager = computed(() => page.component === 'files/Index');
const storageChats = computed(() => (page.props.storageChats ?? []) as {
    id: number;
    name: string;
    chat_title: string | null;
    avatar_url: string | null;
}[]);
const folderItems = computed<NavItem[]>(() => {
    const directories = (page.props.directories ?? []) as {
        id: number;
        name: string;
    }[];
    return directories.map((directory) => ({
        title: directory.name,
        href: filesIndex({ query: { directory_id: directory.id } }),
        icon: Folder,
    }));
});
const fileLocations = computed<NavItem[]>(() => [
    { title: t('All files'), href: filesIndex(), icon: Files },
    {
        title: t('Trash'),
        href: filesIndex({ query: { trash: 1 } }),
        icon: Trash2,
    },
]);

const dashboardUrl = dashboard().url;

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: t('Dashboard'),
        href: dashboardUrl,
        icon: LayoutGrid,
    },
    {
        title: t('File Manager'),
        href: filesIndex(),
        icon: Files,
        children: storageChats.value.map((chat) => ({
            id: chat.id,
            title: chat.chat_title || chat.name,
            href: filesIndex({ query: { telegram_connection_id: chat.id } }),
            avatarUrl: chat.avatar_url,
        })),
    },
    {
        title: t('Media Player'),
        href: mediaIndex(),
        icon: Music2,
    },
    {
        title: t('Tags'),
        href: tagsIndex(),
        icon: Tags,
    },
    {
        title: t('Storage Chats'),
        href: telegramConnections(),
        icon: HardDrive,
    },
]);

const footerNavItems = computed<NavItem[]>(() => [
    {
        title: t('Repository'),
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: t('Documentation'),
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
]);
</script>

<template>
    <Sidebar
        :key="direction"
        :side="direction === 'rtl' ? 'right' : 'left'"
        collapsible="icon"
        variant="inset"
    >
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboardUrl">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <template v-if="isFileManager">
                <SidebarGroup>
                    <SidebarGroupLabel>{{ t('Locations') }}</SidebarGroupLabel>
                    <SidebarGroupContent>
                        <NavMain :items="fileLocations" />
                    </SidebarGroupContent>
                </SidebarGroup>
                <SidebarGroup>
                    <SidebarGroupLabel>{{
                        t('Folders in this location')
                    }}</SidebarGroupLabel>
                    <SidebarGroupContent>
                        <NavMain
                            v-if="folderItems.length"
                            :items="folderItems"
                        />
                        <p
                            v-else
                            class="text-muted-foreground px-2 py-3 text-xs group-data-[collapsible=icon]:hidden"
                        >
                            {{
                                t(
                                    'No folders yet. Create one to organize your files.',
                                )
                            }}
                        </p>
                    </SidebarGroupContent>
                </SidebarGroup>
            </template>
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
