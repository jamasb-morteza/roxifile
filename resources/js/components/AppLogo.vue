<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
</script>

<template>
    <span class="flex min-w-0 items-center">
        <AppLogoIcon
            class="hidden size-8 shrink-0 group-data-[collapsible=icon]:block"
        />
        <img
            src="/roxifile_logo.png"
            alt="RoxiFile"
            width="2172"
            height="724"
            class="h-10 w-auto max-w-full rounded-md bg-white object-contain group-data-[collapsible=icon]:hidden"
        />
    </span>
</template>
