<script setup lang="ts">
import { useLocalization } from '@/composables/useLocalization';
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Appearance settings',
                href: edit(),
            },
        ],
    },
});

const { t } = useLocalization();
</script>

<template>
    <Head title="Appearance settings" />

    <h1 class="sr-only">{{ t('Appearance settings') }}</h1>

    <Card>
        <CardHeader>
            <CardTitle class="text-base">{{ t('Theme') }}</CardTitle>
            <CardDescription>
                Choose how RoxiFile looks on this device. You can always change
                it later.
            </CardDescription>
        </CardHeader>
        <CardContent class="space-y-5">
            <!-- Mini preview -->
            <div
                class="border-border bg-muted/30 relative flex h-36 items-end justify-center gap-3 overflow-hidden rounded-xl border p-4"
            >
                <div
                    class="border-border/70 absolute inset-0 bg-[linear-gradient(to_right,hsl(240_6%_90%)_1px,transparent_1px),linear-gradient(to_bottom,hsl(240_6%_90%)_1px,transparent_1px)] bg-[length:24px_24px] opacity-40"
                />
                <div
                    class="from-primary to-chart-2 relative h-16 w-full max-w-[120px] rounded-lg bg-gradient-to-br shadow-lg"
                />
                <div
                    class="bg-card border-border/70 relative h-16 w-full max-w-[160px] rounded-lg border shadow-sm"
                />
                <div
                    class="bg-primary/15 relative h-16 w-full max-w-[100px] rounded-lg"
                />
                <Badge variant="secondary" class="absolute top-3 left-3">
                    {{ t('Live preview') }}
                </Badge>
            </div>

            <AppearanceTabs />
            <p class="text-muted-foreground text-xs">
                The "System" option follows your operating system preference
                automatically.
            </p>
        </CardContent>
    </Card>
</template>
