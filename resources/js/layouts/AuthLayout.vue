<script setup lang="ts">
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';
import LanguageSelector from '@/components/LanguageSelector.vue';
import { useLocalization } from '@/composables/useLocalization';
import type { TranslationKey } from '@/lib/localization.js';

const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
}>();
const { t } = useLocalization();
</script>

<template>
    <div class="auth-layout relative">
        <header class="absolute end-4 top-4 z-20 md:end-8 md:top-6">
            <LanguageSelector />
        </header>
        <AuthLayout
            :title="title ? t(title as TranslationKey) : title"
            :description="
                description ? t(description as TranslationKey) : description
            "
        >
            <slot />
        </AuthLayout>
    </div>
</template>
