<script setup lang="ts">
import { Search } from '@lucide/vue';
import { computed } from 'vue';
import { Input } from '@/components/ui/input';
import {
    mediaDiscoveryGroups,
    type MediaDiscoveryGroup,
} from '@/lib/mediaDiscovery';
import type { MediaDetails } from '@/lib/mediaMetadata';

const props = defineProps<{
    tracks: {
        name: string;
        protected: boolean;
        details?: MediaDetails | null;
    }[];
}>();
const query = defineModel<string>('query', { required: true });
const group = defineModel<MediaDiscoveryGroup | null>('group', {
    required: true,
});
const categories = [
    { field: 'artist', label: 'Artists' },
    { field: 'album', label: 'Albums' },
    { field: 'genre', label: 'Genres' },
] as const;
const groups = computed(() => mediaDiscoveryGroups(props.tracks, query.value));

function updateQuery(value: string | number): void {
    query.value = String(value);
    group.value = null;
}

function selectGroup(field: MediaDiscoveryGroup['field'], event: Event): void {
    const key = (event.target as HTMLSelectElement).value;
    group.value = key ? { field, key } : null;
}
</script>

<template>
    <section aria-label="Search and browse media" class="space-y-4">
        <div class="relative max-w-md">
            <Search
                class="text-muted-foreground pointer-events-none absolute top-2.5 left-3 size-4"
            />
            <Input
                :model-value="query"
                type="search"
                aria-label="Search tracks, artists and albums"
                placeholder="Search tracks, artists and albums"
                class="pl-9"
                @update:model-value="updateQuery"
            />
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            <label
                v-for="{ field, label } in categories"
                :key="field"
                class="flex min-w-0 flex-col gap-1 text-sm font-medium"
            >
                {{ label }}
                <select
                    class="bg-background w-full rounded-md border px-3 py-2 font-normal"
                    :value="group?.field === field ? group.key : ''"
                    @change="selectGroup(field, $event)"
                >
                    <option value="">
                        {{
                            groups[field].length
                                ? 'All ' + label.toLowerCase()
                                : 'No matches'
                        }}
                    </option>
                    <option
                        v-for="entry in groups[field]"
                        :key="entry.key"
                        :value="entry.key"
                    >
                        {{ entry.name }}
                    </option>
                </select>
            </label>
        </div>
    </section>
</template>
