<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { HardDrive } from '@lucide/vue';
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useLocalization } from '@/composables/useLocalization';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
}>();

const { isCurrentUrl } = useCurrentUrl();
const page = usePage();
const { direction } = useLocalization();
const selectedChatId = computed(() =>
    page.component === 'files/Index'
        ? new URLSearchParams(page.url.split('?')[1] ?? '').get('telegram_connection_id')
        : null,
);
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>Platform</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentUrl(item.href)"
                    :tooltip="item.title"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
                <SidebarMenuSub
                    v-if="item.children?.length"
                    :class="direction === 'rtl' ? 'mr-3.5 ml-0 border-r border-l-0' : ''"
                    data-test="storage-chat-submenu"
                >
                    <SidebarMenuSubItem v-for="child in item.children" :key="child.id">
                        <SidebarMenuSubButton
                            as-child
                            :is-active="selectedChatId === String(child.id)"
                        >
                            <Link :href="child.href" :title="child.title">
                                <Avatar class="size-5">
                                    <AvatarImage v-if="child.avatarUrl" :src="child.avatarUrl" :alt="child.title" />
                                    <AvatarFallback>
                                        <HardDrive class="size-3" />
                                    </AvatarFallback>
                                </Avatar>
                                <span class="truncate">{{ child.title }}</span>
                            </Link>
                        </SidebarMenuSubButton>
                    </SidebarMenuSubItem>
                </SidebarMenuSub>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
