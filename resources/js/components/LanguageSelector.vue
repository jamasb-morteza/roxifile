<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Languages } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { AcceptableValue } from 'reka-ui';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useLocalization } from '@/composables/useLocalization';
import type { Locale } from '@/lib/localization.js';

const page = usePage<{ locale?: Locale }>();
const { t } = useLocalization();
const selectedLocale = computed<Locale>(() =>
    page.props.locale === 'fa' ? 'fa' : 'en',
);
const changing = ref(false);

function changeLanguage(locale: AcceptableValue): void {
    if (
        changing.value ||
        locale === selectedLocale.value ||
        (locale !== 'en' && locale !== 'fa')
    ) {
        return;
    }

    changing.value = true;
    router.post(
        '/locale',
        { locale },
        {
            preserveScroll: true,
            onFinish: () => {
                changing.value = false;
            },
        },
    );
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="size-11 shrink-0"
                :aria-label="t('Language')"
                :title="t('Language')"
                data-test="language-menu-trigger"
            >
                <Languages aria-hidden="true" class="size-5" />
                <span class="sr-only">{{ t('Language') }}</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="min-w-40">
            <DropdownMenuLabel>{{ t('Language') }}</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuRadioGroup
                :model-value="selectedLocale"
                @update:model-value="changeLanguage"
            >
                <DropdownMenuRadioItem value="fa" :disabled="changing">
                    <span lang="fa" dir="rtl">فارسی</span>
                </DropdownMenuRadioItem>
                <DropdownMenuRadioItem value="en" :disabled="changing">
                    <span lang="en" dir="ltr">English</span>
                </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
