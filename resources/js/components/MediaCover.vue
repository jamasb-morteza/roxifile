<script setup lang="ts">
import { ref, watch } from 'vue';
import { preparedDownloadUrl } from '@/lib/browserDownloadTransfer';

const props = defineProps<{ fileId: number | null | undefined; alt: string }>();
const url = ref('');
watch(
    () => props.fileId,
    async (id, _, onCleanup) => {
        url.value = '';
        if (!id) return;

        const request = new AbortController();
        onCleanup(() => request.abort());
        try {
            const prepared = await preparedDownloadUrl(
                id,
                request.signal,
                true,
            );
            request.signal.throwIfAborted();
            url.value = prepared;
        } catch {
            // The selected cover may have been removed or be unavailable.
        }
    },
    { immediate: true },
);
</script>

<template>
    <img
        v-if="url"
        :src="url"
        :alt="alt"
        class="size-28 rounded-lg object-cover"
        @error="url = ''"
    />
</template>
