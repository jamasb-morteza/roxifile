<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';

const { appearance, updateAppearance } = useAppearance();

const tabs = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;
</script>

<template>
    <div
        class="border-border bg-card grid gap-3 rounded-xl border sm:grid-cols-3"
    >
        <button
            v-for="{ value, Icon, label } in tabs"
            :key="value"
            type="button"
            @click="updateAppearance(value)"
            :class="[
                'group focus-visible:ring-ring flex flex-col items-center gap-3 rounded-xl p-4 text-sm font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
                appearance === value
                    ? 'ring-primary bg-primary/5 ring-2'
                    : 'hover:bg-muted/60 ring-border ring-1',
            ]"
        >
            <span
                class="relative flex h-12 w-20 items-center justify-center overflow-hidden rounded-lg border border-neutral-200"
            >
                <template v-if="value === 'light'">
                    <span class="absolute inset-0 bg-white" />
                    <span
                        class="from-primary to-chart-2 absolute right-2 bottom-2 h-5 w-8 rounded-md bg-gradient-to-br"
                    />
                </template>
                <template v-else-if="value === 'dark'">
                    <span class="absolute inset-0 bg-neutral-950" />
                    <span
                        class="from-primary to-chart-2 absolute right-2 bottom-2 h-5 w-8 rounded-md bg-gradient-to-br"
                    />
                </template>
                <template v-else>
                    <span class="absolute inset-0 bg-white" />
                    <span
                        class="absolute top-0 right-0 h-full w-1/3 bg-neutral-950"
                    />
                    <span
                        class="from-primary to-chart-2 absolute right-2 bottom-2 z-10 h-5 w-8 rounded-md bg-gradient-to-br"
                    />
                </template>
                <component
                    :is="Icon"
                    :class="[
                        'relative z-20 size-4 drop-shadow-sm',
                        value === 'dark'
                            ? 'text-neutral-50'
                            : 'text-neutral-500',
                    ]"
                />
            </span>
            {{ label }}
        </button>
    </div>
</template>
