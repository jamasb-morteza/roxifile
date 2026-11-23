import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = (path) =>
    readFileSync(
        new URL(`../../resources/js/${path}`, import.meta.url),
        'utf8',
    );

test('storage chats appears in main navigation without changing existing route', () => {
    const sidebar = source('components/AppSidebar.vue');

    assert.match(
        sidebar,
        /title: (?:'Storage Chats'|t\('Storage Chats'\)),[\s\S]*?href: telegramConnections\(\)/,
    );
    assert.match(sidebar, /icon: HardDrive/);
    assert.doesNotMatch(sidebar, /Telegram Connections/);
});

test('storage chats page distinguishes storage destination from Telegram access methods', () => {
    const page = source('pages/telegram/Connections.vue');

    assert.match(page, /<Head (?:title="Storage Chats"|:title="t\('Storage Chats'\)")\s*\/>/);
    assert.match(page, /(?:title="Storage Chats"|:title="t\('Storage Chats'\)")/);
    assert.match(
        page,
        /Connect Telegram groups or channels to store and access your files/,
    );
    assert.match(page, /Connect Chat/);
    assert.match(page, /Storage name in RoxiFile/);
    assert.match(page, /Telegram access method/);
    assert.match(page, /optional personal Telegram account/);
    assert.match(page, /Roxi is RoxiFile's Telegram identity/);
    assert.match(page, /Create Storage Chat/);
    assert.match(page, /Disconnect chat/);
    assert.doesNotMatch(
        page,
        /(?:Telegram Connections|Add Connection|Delete connection)/,
    );
});

test('file manager uses matching storage chat vocabulary and offers a direct setup link', () => {
    const page = source('pages/files/Index.vue');

    assert.match(page, /Storage Chats/);
    assert.match(page, /telegramConnections\(\)/);
    assert.match(
        page,
        /<Link[\s\S]*?:href="telegramConnections\(\)"[\s\S]*?>[\s\S]*?Connect Chat[\s\S]*?<\/Link>/,
    );
    assert.doesNotMatch(page, /<SelectValue placeholder="Storage chat"\s*\/>/);
    assert.match(page, /data-test="file-chat-column"/);
    assert.match(page, /data-test="file-chat-cell"/);
    assert.match(page, /data-test="upload-destination-dialog"/);
    assert.doesNotMatch(page, /Telegram Connections/);
});

test('File Manager has one child per storage chat with a photo and filtered link', () => {
    const sidebar = source('components/AppSidebar.vue');
    const navigation = source('components/NavMain.vue');
    const middleware = readFileSync(
        new URL('../../app/Http/Middleware/HandleInertiaRequests.php', import.meta.url),
        'utf8',
    );

    assert.match(sidebar, /storageChats\.value\.map/);
    assert.match(sidebar, /telegram_connection_id: chat\.id/);
    assert.match(sidebar, /avatarUrl: chat\.avatar_url/);
    assert.match(navigation, /<SidebarMenuSub/);
    assert.match(navigation, /<AvatarImage v-if="child\.avatarUrl"/);
    assert.match(navigation, /selectedChatId === String\(child\.id\)/);
    assert.match(middleware, /'storageChats'/);
});

test('combined File Manager does not silently choose an upload chat', () => {
    const page = source('pages/files/Index.vue');
    assert.match(page, /props\.connections\.length > 1/);
    assert.match(page, /uploadDestinationOpen\.value = true/);
    assert.match(page, /function confirmUploadDestination/);
    assert.match(page, /@click="openUploadPicker"/);
});
