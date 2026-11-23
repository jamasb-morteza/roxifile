<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

const page = usePage();
const isFileManager = computed(() => page.component === 'files/Index');

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <AppSidebarHeader
                v-if="!isFileManager"
                :breadcrumbs="breadcrumbs"
            />
            <!-- Single source of truth for page content padding. -->
            <div class="flex flex-1 flex-col gap-4 p-4 md:px-6">
                <slot />
            </div>
        </AppContent>
        <Toaster />
    </AppShell>
</template>
