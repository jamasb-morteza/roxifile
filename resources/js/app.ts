import { createInertiaApp, router } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { syncDocumentLanguage } from '@/lib/localization.js';
import { configureEcho } from '@laravel/echo-vue';

configureEcho({
    broadcaster: 'reverb',
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

router.on('navigate', (event) => {
    const props = event.detail.page.props as { locale?: string };
    syncDocumentLanguage(props.locale ?? 'en');
});

// Same-page Inertia visits (such as POST /locale followed by a redirect) emit
// `success` without `navigate`. Keep <html> and <body> in sync in both cases.
router.on('success', (event) => {
    const props = event.detail.page.props as { locale?: string };
    syncDocumentLanguage(props.locale ?? 'en');
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
