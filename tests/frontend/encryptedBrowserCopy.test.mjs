import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { createServer } from 'vite';
import {
    createVault,
    encryptFile,
    encryptMetadata,
} from '../../resources/js/lib/contentsEncryption.ts';
import { sanitizeBrowserSnapshot } from '../../resources/js/lib/browserDownloads.ts';

const root = path.resolve(import.meta.dirname, '../..');
const vite = await createServer({
    configFile: false,
    root,
    cacheDir: path.join(root, 'node_modules/.vite-encrypted-browser-test'),
    optimizeDeps: { noDiscovery: true },
    resolve: { alias: { '@': path.join(root, 'resources/js') } },
    server: { middlewareMode: true, watch: null },
});
const { decryptBrowserCopy, unlockBrowserCopy } = await vite.ssrLoadModule(
    '/resources/js/lib/encryptedBrowserCopy.ts',
);
test.after(async () => vite.close());

async function makeCopy(mode) {
    const context = { userId: 7, connectionId: 19 };
    const vault = await createVault('separate offline vault password', context);
    const plaintext = 'offline encrypted media bytes';
    const encrypted = await encryptFile(
        new Blob([plaintext]),
        vault.key,
        context,
    );
    const header = Buffer.from(
        await encrypted.file.slice(0, 64).arrayBuffer(),
    ).toString('base64');
    const metadata =
        mode === 'contents_and_metadata'
            ? await encryptMetadata(
                  {
                      name: 'private-photo.txt',
                      mime: 'text/plain',
                      tags: ['private'],
                      description: 'offline notes',
                  },
                  vault.key,
                  context,
                  'opaque-copy-1',
              )
            : null;
    return {
        copy: {
            id: 1,
            name:
                mode === 'contents_and_metadata'
                    ? 'Protected file'
                    : 'photo.txt',
            storage_name: 'opaque-copy-1',
            mime_type:
                mode === 'contents_and_metadata'
                    ? 'application/octet-stream'
                    : 'text/plain',
            size: encrypted.file.size,
            directory_path: 'Readable folder',
            description: null,
            tags: [],
            connection_id: context.connectionId,
            encryption_mode: mode,
            encryption_header: header,
            metadata_protected: mode === 'contents_and_metadata',
            protected_metadata: metadata,
            vault_envelope: vault.envelope,
            account: context.userId,
            path: 'ciphertext-copy',
            saved_at: '2026-10-30T00:00:00.000Z',
            original_missing: false,
        },
        encrypted,
        context,
        plaintext,
    };
}

void test('offline encrypted copies unlock from wrapped keys for both scopes after reopening', async () => {
    for (const mode of ['contents', 'contents_and_metadata']) {
        const { copy, encrypted, plaintext } = await makeCopy(mode);

        const reopenedCopy = structuredClone(copy);
        assert.equal('raw_key' in reopenedCopy, false);
        const opened = await unlockBrowserCopy(
            reopenedCopy,
            7,
            'separate offline vault password',
        );
        const decrypted = await decryptBrowserCopy(
            reopenedCopy,
            7,
            opened.key,
            encrypted.file,
        );

        assert.equal(await decrypted.file.text(), plaintext);
        assert.equal(
            opened.metadata?.name ?? reopenedCopy.name,
            mode === 'contents_and_metadata'
                ? 'private-photo.txt'
                : 'photo.txt',
        );
        if (mode === 'contents_and_metadata') {
            assert.equal(opened.metadata?.description, 'offline notes');
            assert.deepEqual(opened.metadata?.tags, ['private']);
            assert.equal(reopenedCopy.description, null);
            assert.deepEqual(reopenedCopy.tags, []);
            assert.equal(reopenedCopy.mime_type, 'application/octet-stream');
        }
        await decrypted.dispose();
        await encrypted.dispose();
    }
});

void test('offline encrypted copies reject wrong credentials, foreign accounts, and damaged ciphertext', async () => {
    const { copy, encrypted } = await makeCopy('contents');

    await assert.rejects(
        unlockBrowserCopy(copy, 7, 'wrong offline password'),
        /wrong credential|damaged vault/i,
    );
    await assert.rejects(
        unlockBrowserCopy(copy, 8, 'separate offline vault password'),
    );
    const opened = await unlockBrowserCopy(
        copy,
        7,
        'separate offline vault password',
    );
    const bytes = new Uint8Array(await encrypted.file.arrayBuffer());
    bytes[bytes.length - 1] ^= 1;
    await assert.rejects(
        decryptBrowserCopy(copy, 7, opened.key, new Blob([bytes])),
    );
    await encrypted.dispose();
});

void test('aborting during delayed destination close prevents the plaintext commit', async () => {
    const { copy, encrypted } = await makeCopy('contents');
    const { key } = await unlockBrowserCopy(
        copy,
        7,
        'separate offline vault password',
    );
    const controller = new AbortController();
    let markCloseStarted;
    const closeStarted = new Promise((resolve) => {
        markCloseStarted = resolve;
    });
    let releaseClose;
    let aborted = false;
    let committed = false;
    const destination = {
        write: async () => {},
        close: async () => {
            markCloseStarted();
            await new Promise((resolve) => {
                releaseClose = resolve;
            });
            if (aborted) {
                throw new DOMException('Aborted', 'AbortError');
            }
            committed = true;
        },
        abort: async () => {
            aborted = true;
        },
    };
    const decryption = decryptBrowserCopy(
        copy,
        7,
        key,
        encrypted.file,
        controller.signal,
        destination,
    );

    await closeStarted;
    controller.abort();
    releaseClose();

    await assert.rejects(decryption, { name: 'AbortError' });
    assert.equal(aborted, true);
    assert.equal(committed, false);
    await encrypted.dispose();
});

void test('protected offline catalog metadata stores no plaintext details or raw key fields', () => {
    const snapshot = {
        id: 4,
        name: 'secret-name.jpg',
        storage_name: 'opaque-file-4',
        mime_type: 'image/jpeg',
        size: 120,
        directory_path: 'Readable folder',
        description: 'secret description',
        tags: [{ id: 8, name: 'secret tag' }],
        connection_id: 19,
        encryption_mode: 'contents_and_metadata',
        encryption_header: 'ciphertext-header',
        metadata_protected: true,
        protected_metadata: {
            version: 1,
            salt: 'salt',
            iv: 'iv',
            ciphertext: 'sealed metadata',
        },
        vault_envelope: {
            version: 1,
            salt: 'salt',
            iterations: 600000,
            password: { iv: 'password-iv', ciphertext: 'wrapped-key' },
            recovery: { iv: 'recovery-iv', ciphertext: 'wrapped-key' },
            raw_key: 'must never persist',
        },
        raw_key: 'must never persist',
    };

    const stored = sanitizeBrowserSnapshot(snapshot);

    assert.equal(stored.name, 'Protected file');
    assert.equal(stored.mime_type, 'application/octet-stream');
    assert.equal(stored.description, null);
    assert.deepEqual(stored.tags, []);
    assert.equal('raw_key' in stored, false);
    assert.equal(JSON.stringify(stored).includes('secret-name'), false);
    assert.equal(JSON.stringify(stored).includes('secret description'), false);
    assert.equal(JSON.stringify(stored).includes('secret tag'), false);
    assert.equal(stored.protected_metadata?.ciphertext, 'sealed metadata');
});
