<script setup lang="ts">
import { onMounted, ref } from 'vue';
import {
    mediaThemes,
    readMediaTheme,
    saveMediaTheme,
    type MediaTheme,
} from '@/lib/mediaTheme';

const props = defineProps<{ account: number }>();
const theme = defineModel<MediaTheme>({ required: true });
const error = ref(false);

onMounted(() => {
    try {
        theme.value = readMediaTheme(window.localStorage, props.account);
    } catch {
        error.value = true;
    }
});

function changeTheme(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    const selected = mediaThemes.find((option) => option === value);
    if (!selected) return;

    theme.value = selected;
    try {
        saveMediaTheme(window.localStorage, props.account, selected);
        error.value = false;
    } catch {
        error.value = true;
    }
}
</script>

<template>
    <div>
        <label class="flex flex-wrap items-center gap-2 text-sm">
            Player theme
            <select
                class="rounded-md border px-2 py-1 capitalize"
                :value="theme"
                @change="changeTheme"
            >
                <option
                    v-for="option in mediaThemes"
                    :key="option"
                    :value="option"
                >
                    {{ option }}
                </option>
            </select>
        </label>
        <p v-if="error" role="status" class="mt-1 text-sm">
            Your browser could not save the player theme.
        </p>
    </div>
</template>
