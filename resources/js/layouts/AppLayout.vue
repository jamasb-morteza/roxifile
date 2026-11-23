<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { setBrowserAccount } from '@/lib/browserDownloads';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const page = usePage();
watch(
    () => page.props.auth.user?.id,
    (id) => {
        if (id) {
            try {
                setBrowserAccount(Number(id));
            } catch {
                /* Browser downloads report storage restrictions in their own UI. */
            }
        }
    },
    { immediate: true },
);

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppLayout>
</template>
