import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(
    new URL('../../resources/js/pages/files/Index.vue', import.meta.url),
    'utf8',
);

test('file preview resolves a browser copy before preparing a remote download', () => {
    const preview = page.slice(
        page.indexOf('function preview('),
        page.indexOf('const prepareDownloadForm'),
    );
    assert.match(preview, /await readBrowserCopy\(vaultUserId, file.id\)/);
    assert.match(
        preview,
        /receiveEncryptedDownload\(file, null, true, local\)/,
    );
    assert.match(
        preview,
        /URL\.createObjectURL\(\s*local\.slice\(0, local\.size, file\.mime_type\),?\s*\)/,
    );
    assert.equal(preview.match(/download\(file, true\)/g)?.length, 1);
    assert.ok(
        preview.indexOf('await readBrowserCopy') <
            preview.indexOf('download(file, true)'),
    );
});

test('normal file rows expose all three common actions', () => {
    const actions = page.slice(
        page.indexOf('data-test="file-row"'),
        page.indexOf('<!-- Listing (grid view) -->'),
    );
    assert.match(
        actions,
        /v-if="!filters\.trash"[^>]*data-test="file-actions"/s,
    );
    assert.match(actions, /@click="download\(file\)"/);
    assert.match(actions, /@click="saveBrowserDownload\(file\)"/);
    assert.match(actions, /@click="deleteEntry\(file, 'file'\)"/);
});
