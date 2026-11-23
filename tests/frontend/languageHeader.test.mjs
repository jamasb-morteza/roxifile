import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const component = async (path) =>
    readFile(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');

test('language switcher uses a keyboard-accessible language icon and a selected-language menu', async () => {
    const selector = await component('components/LanguageSelector.vue');

    assert.match(selector, /<Languages\s+aria-hidden="true"/);
    assert.match(selector, /data-test="language-menu-trigger"/);
    assert.match(selector, /:aria-label="t\('Language'\)"/);
    assert.match(
        selector,
        /<span class="sr-only">\{\{ t\('Language'\) \}\}<\/span>/,
    );
    assert.match(
        selector,
        /<DropdownMenuRadioGroup[\s\S]*?:model-value="selectedLocale"/,
    );
    assert.match(selector, /@update:model-value="changeLanguage"/);
    assert.match(selector, /<DropdownMenuRadioItem value="fa"/);
    assert.match(selector, /<DropdownMenuRadioItem value="en"/);
    assert.match(selector, /<span lang="fa" dir="rtl">فارسی<\/span>/);
    assert.match(selector, /<span lang="en" dir="ltr">English<\/span>/);
    assert.match(selector, /router\.post\([\s\S]*?'\/locale'/);
    assert.match(selector, /locale === selectedLocale\.value/);
    assert.doesNotMatch(selector, /<select\b/);
});

test('language icon appears in all application header variants, including the file manager on mobile', async () => {
    const sidebarHeader = await component('components/AppSidebarHeader.vue');
    const topHeader = await component('components/AppHeader.vue');
    const fileManager = await component('pages/files/Index.vue');
    const sidebar = await component('components/AppSidebar.vue');
    const layout = await component('layouts/app/AppSidebarLayout.vue');

    assert.match(
        sidebarHeader,
        /<header[\s\S]*?<LanguageSelector\s*\/>[\s\S]*?<\/header>/,
    );
    assert.match(topHeader, /<LanguageSelector\s*\/>/);
    assert.doesNotMatch(topHeader, /<LanguageSelector[^>]*hidden/);
    assert.match(
        fileManager,
        /<header[^>]*data-test="file-manager-header"[\s\S]*?<LanguageSelector\s*\/>[\s\S]*?<\/header>/,
    );
    assert.match(layout, /v-if="!isFileManager"/);
    assert.doesNotMatch(sidebar, /LanguageSelector/);
});

test('landing and authentication pages present the same icon in their header', async () => {
    const landing = await component('pages/Welcome.vue');
    const auth = await component('layouts/AuthLayout.vue');

    assert.match(
        landing,
        /<header[\s\S]*?<LanguageSelector\s*\/>[\s\S]*?<\/header>/,
    );
    assert.match(
        auth,
        /<header[^>]*[\s\S]*?<LanguageSelector\s*\/>[\s\S]*?<\/header>/,
    );
    assert.match(auth, /\bend-4\b/);
});
