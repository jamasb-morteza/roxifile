import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import en from '../../lang/en.json' with { type: 'json' };
import fa from '../../lang/fa.json' with { type: 'json' };
import {
    getLocaleAttributes,
    translate,
} from '../../resources/js/lib/localization.js';

const source = async (path) =>
    readFile(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');

test('Persian puts the navigation sidebar on the right and English on the left', async () => {
    const appSidebar = await source('components/AppSidebar.vue');
    const appShell = await source('components/AppShell.vue');
    const sidebarInset = await source('components/ui/sidebar/SidebarInset.vue');
    const toggle = await source('components/ui/sidebar/SidebarTrigger.vue');

    assert.equal(getLocaleAttributes('fa').direction, 'rtl');
    assert.equal(getLocaleAttributes('en').direction, 'ltr');
    assert.match(appSidebar, /:side="direction === 'rtl' \? 'right' : 'left'"/);
    // Inertia keeps the layout mounted: its flex direction must follow the
    // same reactive locale as the fixed-position sidebar, not a stale body dir.
    assert.match(appShell, /useLocalization/);
    assert.match(appShell, /<SidebarProvider[^>]*:dir="direction"/);
    // Inertia preserves the parent layout during a POST /locale redirect. The
    // sidebar must remount when direction changes so fixed/mobile positioning
    // and the spacer cannot retain stale left/right placement.
    assert.match(appSidebar, /<Sidebar\s+:key="direction"/);
    assert.match(sidebarInset, /:ms-0/);
    assert.match(sidebarInset, /:ms-2/);
    assert.doesNotMatch(sidebarInset, /:ml-0|:ml-2/);
    assert.match(toggle, /direction === 'rtl'/);
    assert.match(toggle, /PanelRightOpen/);
    assert.match(toggle, /PanelRightClose/);
    assert.match(toggle, /PanelLeftOpen/);
    assert.match(toggle, /PanelLeftClose/);
    assert.equal(
        translate('Toggle sidebar', 'fa'),
        'باز و بسته‌کردن نوار کناری',
    );
});

test('Home is a responsive localized product landing page rather than a generic scaffold', async () => {
    const home = await source('pages/Welcome.vue');
    assert.match(home, /<Head :title="t\('Welcome'\)"/);
    assert.match(home, /const \{ t, direction \} = useLocalization\(\)/);
    assert.match(home, /<div\s+:dir="direction"/);
    assert.match(home, /<LanguageSelector\s*\/>/);
    assert.match(home, /lg:grid-cols-/);
    assert.match(home, /sm:grid-cols-2/);
    assert.match(home, /md:grid-cols-3/);
    assert.match(home, /min-h-dvh/);
    assert.match(home, /href="#main-content"/);
    assert.match(home, /id="features"/);
    assert.match(home, /id="how-it-works"/);
    assert.match(home, /primaryDestination/);
    assert.match(home, /isAuthenticated/);
    assert.match(home, /rtl-mirror/);
    assert.match(home, /Illustrative RoxiFile file manager preview/);
    assert.doesNotMatch(home, /Built with.*Laravel/);
});

test('All literal translated copy in the redesigned Home exists in English and Persian', async () => {
    const home = await source('pages/Welcome.vue');
    for (const match of home.matchAll(/\bt\(\s*'([^']+)'/g)) {
        const key = match[1];
        assert.equal(en[key], key, `Missing English key ${key}`);
        assert.ok(fa[key], `Missing Persian key ${key}`);
    }
    assert.equal(translate('a better place.', 'fa'), 'جای بهتری هستند.');
    assert.equal(translate('Features', 'fa'), 'ویژگی‌ها');
});
