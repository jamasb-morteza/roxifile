<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <img
        src="/favicon.png"
        alt="RoxiFile"
        width="1254"
        height="1254"
        :class="className"
        class="object-contain"
        v-bind="$attrs"
    />
</template>
