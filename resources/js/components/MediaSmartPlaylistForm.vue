<script setup lang="ts">
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { MediaPlaylist, MediaSmartRules } from '@/lib/browserDownloads';

const props = defineProps<{ playlist?: MediaPlaylist }>();
const emit = defineEmits<{ save: [name: string, rules: MediaSmartRules] }>();
const name = ref(props.playlist?.name ?? '');
const tag = ref(props.playlist?.smart_rules?.tag ?? '');
const metadata = ref(props.playlist?.smart_rules?.metadata ?? '');
const rating = ref(props.playlist?.smart_rules?.minimum_rating ?? '');
const plays = ref(props.playlist?.smart_rules?.minimum_plays ?? '');
const favorite = ref(props.playlist?.smart_rules?.favorite_only ?? false);

function save(): void {
    if (!name.value.trim()) return;
    emit('save', name.value.trim(), {
        tag: tag.value.trim() || null,
        minimum_rating: Number(rating.value) || null,
        favorite_only: favorite.value,
        metadata: metadata.value.trim() || null,
        minimum_plays: Number(plays.value) || null,
    });
}
</script>

<template>
    <form class="grid gap-3" @submit.prevent="save">
        <label class="grid gap-1 text-sm"
            >Name <Input v-model="name" maxlength="100" required
        /></label>
        <label class="grid gap-1 text-sm"
            >Tag <Input v-model="tag" maxlength="255"
        /></label>
        <label class="grid gap-1 text-sm"
            >Title, artist, album or genre
            <Input v-model="metadata" maxlength="255"
        /></label>
        <label class="grid gap-1 text-sm"
            >Minimum rating
            <Input v-model="rating" type="number" min="1" max="10"
        /></label>
        <label class="grid gap-1 text-sm"
            >Minimum play count
            <Input v-model="plays" type="number" min="1" max="100000"
        /></label>
        <label class="flex items-center gap-2 text-sm"
            ><input v-model="favorite" type="checkbox" /> Favorites only</label
        >
        <Button type="submit">Save smart playlist</Button>
    </form>
</template>
