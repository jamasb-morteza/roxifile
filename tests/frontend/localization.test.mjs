import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import test from 'node:test';
import englishMessages from '../../lang/en.json' with { type: 'json' };
import persianMessages from '../../lang/fa.json' with { type: 'json' };
import {
    formatDate,
    formatFileSize,
    formatNumber,
    getLocaleAttributes,
    syncDocumentLanguage,
    translate,
} from '../../resources/js/lib/localization.js';

test('English is the complete translation fallback and Persian has matching keys', async () => {
    assert.deepEqual(
        Object.keys(persianMessages).sort(),
        Object.keys(englishMessages).sort(),
    );
    assert.equal(translate('Dashboard', 'en'), 'Dashboard');
    assert.equal(translate('Dashboard', 'fa'), 'داشبورد');
    assert.equal(translate('Missing translation', 'fa'), 'Missing translation');

    const english = await readFile(
        new URL('../../lang/en.json', import.meta.url),
        'utf8',
    );
    assert.doesNotMatch(english, /""\s*:/);
});

test('locale switching updates document language and direction in both directions', () => {
    const attributes = new Map();
    const bodyAttributes = new Map();
    globalThis.document = {
        documentElement: {
            lang: 'en',
            dir: 'ltr',
            setAttribute(name, value) {
                attributes.set(name, value);
            },
        },
        body: {
            setAttribute(name, value) {
                bodyAttributes.set(name, value);
            },
        },
    };

    syncDocumentLanguage('fa');
    assert.deepEqual(getLocaleAttributes('fa'), {
        lang: 'fa',
        direction: 'rtl',
    });
    assert.equal(attributes.get('lang'), 'fa');
    assert.equal(attributes.get('dir'), 'rtl');
    assert.equal(bodyAttributes.get('dir'), 'rtl');

    syncDocumentLanguage('en');
    assert.deepEqual(getLocaleAttributes('en'), {
        lang: 'en',
        direction: 'ltr',
    });
    assert.equal(attributes.get('dir'), 'ltr');
    assert.equal(bodyAttributes.get('dir'), 'ltr');
    delete globalThis.document;
});

test('inertia locale visits update document direction even on same-page redirects', async () => {
    const app = await readFile(
        new URL('../../resources/js/app.ts', import.meta.url),
        'utf8',
    );
    assert.match(app, /router\.on\('navigate'/);
    assert.match(app, /router\.on\('success'/);
    assert.match(app, /syncDocumentLanguage\(props\.locale \?\? 'en'\)/g);
});

test('numbers, file sizes and dates are localized for display only', () => {
    const timestamp = '2024-01-15T12:00:00Z';

    assert.match(formatNumber(12345, 'fa'), /[۰-۹]/u);
    assert.match(formatFileSize(1536, 'fa'), /[۰-۹].*KB/u);
    assert.notEqual(formatDate(timestamp, 'fa'), formatDate(timestamp, 'en'));
    assert.equal(timestamp, '2024-01-15T12:00:00Z');
});

test('Persian uses RTL styles but technical strings remain left-to-right', async () => {
    const css = await readFile(
        new URL('../../resources/css/app.css', import.meta.url),
        'utf8',
    );
    assert.match(css, /html\[lang='fa'\]/);
    assert.match(css, /\[dir='rtl'\]/);
    assert.match(css, /input\[type='email'\]/);
    assert.match(css, /unicode-bidi: isolate/);
});

test('offline downloads and media export use shared translated copy', async () => {
    for (const path of [
        '../../resources/js/components/BrowserDownloads.vue',
        '../../resources/js/components/MediaExportDialog.vue',
        '../../resources/js/components/OfflineEncryptedCopy.vue',
    ]) {
        const component = await readFile(
            new URL(path, import.meta.url),
            'utf8',
        );
        assert.match(component, /useLocalization/);
        assert.match(component, /\bt\(/);
    }

    for (const key of [
        'Browser downloads',
        'Save for offline use',
        'Browser storage is unavailable. Use a regular download.',
        'Export tracks',
        'Start downloads',
        'Enter the vault credential to download this track.',
        'Encrypted copy · Unlock this vault to open it.',
        'Unlock saved copy',
        'Preparing encrypted media…',
    ]) {
        assert.ok(englishMessages[key], `Missing English key: ${key}`);
        assert.ok(persianMessages[key], `Missing Persian key: ${key}`);
    }
});

test('media viewing, player controls and two-factor setup have localized messages and accessible labels', async () => {
    const components = {
        MediaViewer: await readFile(
            new URL(
                '../../resources/js/components/MediaViewer.vue',
                import.meta.url,
            ),
            'utf8',
        ),
        MediaControls: await readFile(
            new URL(
                '../../resources/js/components/MediaControls.vue',
                import.meta.url,
            ),
            'utf8',
        ),
        TwoFactorSetupModal: await readFile(
            new URL(
                '../../resources/js/components/TwoFactorSetupModal.vue',
                import.meta.url,
            ),
            'utf8',
        ),
    };

    for (const [name, source] of Object.entries(components)) {
        assert.match(
            source,
            /useLocalization/,
            `${name} must read the active Inertia locale`,
        );
        assert.match(
            source,
            /\bt\(/,
            `${name} must use shared translation keys`,
        );
    }

    for (const key of [
        'Preview file contents and details.',
        'This browser cannot stream protected media. Use Download.',
        'Media could not be decoded.',
        'Playback could not start in this browser.',
        'Live audio waveform',
        'Playback position',
        'Exit fullscreen',
        'Equalizer',
        'Two-factor authentication enabled',
        'Verify authentication code',
        'To finish enabling two-factor authentication, scan the QR code or enter the setup key in your authenticator app',
        'or, enter the code manually',
    ]) {
        assert.equal(
            englishMessages[key],
            key,
            `Missing English source: ${key}`,
        );
        assert.ok(persianMessages[key], `Missing Persian translation: ${key}`);
        assert.notEqual(
            persianMessages[key],
            key,
            `Untranslated Persian key: ${key}`,
        );
    }

    assert.match(
        components.MediaControls,
        /:aria-label="t\('Playback position'\)"/,
    );
    assert.match(components.MediaViewer, /class="pe-12"/);
    assert.match(
        components.TwoFactorSetupModal,
        /dir="ltr"[\s\S]*?technical-ltr/,
    );
    assert.match(components.TwoFactorSetupModal, /border-s px-3/);
});

test('security actions use shared localized copy without translating user-owned values', async () => {
    const names = [
        'DeleteUser',
        'ManageTwoFactor',
        'TwoFactorRecoveryCodes',
        'PasskeyRegister',
        'PasskeyItem',
        'PasskeyVerify',
    ];

    for (const name of names) {
        const source = await readFile(
            new URL(
                `../../resources/js/components/${name}.vue`,
                import.meta.url,
            ),
            'utf8',
        );
        assert.match(
            source,
            /useLocalization/,
            `${name} should react to the active locale`,
        );
        assert.match(
            source,
            /\bt\(/,
            `${name} should translate user-visible copy`,
        );
    }

    const passkeys = await readFile(
        new URL(
            '../../resources/js/components/PasskeyItem.vue',
            import.meta.url,
        ),
        'utf8',
    );
    assert.match(passkeys, /\{\{ passkey.name \}\}/);
    assert.match(passkeys, /name: passkey.name/);
    const recovery = await readFile(
        new URL(
            '../../resources/js/components/TwoFactorRecoveryCodes.vue',
            import.meta.url,
        ),
        'utf8',
    );
    assert.match(recovery, /dir="ltr"/);
    assert.match(recovery, /\{\{ code \}\}/);

    for (const key of [
        'Delete account',
        'Are you sure you want to delete your account?',
        'Two-factor authentication',
        'View recovery codes',
        'Each recovery code can be used once to access your account and will be removed after use. If you need more, click Regenerate codes above.',
        'Passkeys are not supported in this browser.',
        'Add passkey',
        'Are you sure you want to remove the ":name" passkey? You will no longer be able to use it to sign in.',
        'Sign in with a passkey',
    ]) {
        assert.equal(englishMessages[key], key);
        assert.ok(persianMessages[key]);
        assert.notEqual(persianMessages[key], key);
    }
});

test('all directly referenced Vue translation keys exist in both locale dictionaries', async () => {
    const jsRoot = new URL('../../resources/js/', import.meta.url);
    for (const path of await readdir(jsRoot, { recursive: true })) {
        if (!/\.(?:vue|ts|js)$/.test(path)) continue;
        const source = await readFile(new URL(path, jsRoot), 'utf8');
        if (!source.includes('useLocalization')) continue;

        for (const match of source.matchAll(/\bt\(\s*'((?:\\'|[^'])*)'/g)) {
            const key = match[1].replaceAll("\\'", "'");
            assert.ok(
                Object.hasOwn(englishMessages, key),
                `${path}: missing English key ${key}`,
            );
            assert.ok(
                Object.hasOwn(persianMessages, key),
                `${path}: missing Persian key ${key}`,
            );
        }
    }
});
