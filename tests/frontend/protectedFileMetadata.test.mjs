import test from 'node:test';
import assert from 'node:assert/strict';
import {
    createVault,
    encryptMetadata,
} from '../../resources/js/lib/contentsEncryption.ts';
import {
    unlockFileMetadata,
    filterProtectedFiles,
} from '../../resources/js/lib/contentsEncryption.ts';

void test('protected listing and filtering use authenticated local metadata without changing server entries', async () => {
    const context = { userId: 7, connectionId: 19 };
    const vault = await createVault('separate vault password', context);
    const metadata = {
        name: 'private budget.pdf',
        mime: 'application/pdf',
        tags: ['private finance'],
        description: 'annual figures',
    };
    const envelope = await encryptMetadata(
        metadata,
        vault.key,
        context,
        'opaque-1',
    );
    const stored = {
        id: 1,
        connection_id: 19,
        storage_name: 'opaque-1',
        name: 'opaque-1',
        mime_type: 'application/octet-stream',
        file_type: 'other',
        tags: [],
        description: null,
        directory_path: 'Readable/2026',
        metadata_protected: true,
        protected_metadata: envelope,
    };
    const file = await unlockFileMetadata(stored, vault.key, context);
    assert.equal(file.name, 'private budget.pdf');
    assert.equal(file.file_type, 'pdf');
    assert.equal(stored.name, 'opaque-1');
    assert.equal(stored.tags.length, 0);
    assert.equal(JSON.stringify(stored).includes('private'), false);
    assert.deepEqual(
        filterProtectedFiles(
            [file],
            'BUDGET',
            'private finance',
            'pdf',
            'application/pdf',
        ),
        [file],
    );
    assert.deepEqual(
        filterProtectedFiles([file], 'readable/2026', 'all', 'all', ''),
        [file],
    );
    assert.deepEqual(
        filterProtectedFiles([file], 'annual figures', 'all', 'all', ''),
        [file],
    );
    assert.deepEqual(
        filterProtectedFiles([file], '', 'wrong tag', 'all', ''),
        [],
    );
    assert.deepEqual(filterProtectedFiles([file], '', 'all', 'image', ''), []);
    assert.deepEqual(filterProtectedFiles([stored], '', 'all', 'all', ''), []);
    await assert.rejects(
        unlockFileMetadata(
            { ...stored, protected_metadata: null },
            vault.key,
            context,
        ),
    );
    await assert.rejects(
        unlockFileMetadata(
            { ...stored, storage_name: 'opaque-2' },
            vault.key,
            context,
        ),
    );
    await assert.rejects(
        unlockFileMetadata(stored, vault.key, { ...context, userId: 8 }),
    );
    await assert.rejects(
        unlockFileMetadata(stored, vault.key, { ...context, connectionId: 20 }),
    );
    await assert.rejects(
        unlockFileMetadata(
            {
                ...stored,
                protected_metadata: {
                    ...envelope,
                    ciphertext: envelope.ciphertext.slice(4),
                },
            },
            vault.key,
            context,
        ),
    );
});

void test('protected metadata refuses invalid names, MIME and payloads exceeding the server envelope limit', async () => {
    const context = { userId: 7, connectionId: 19 };
    const vault = await createVault('separate vault password', context);
    const base = {
        name: 'valid.txt',
        mime: 'text/plain',
        description: '',
        tags: [],
    };
    for (const change of [
        { name: '..' },
        { mime: 'text/html; arbitrary' },
        { tags: Array(100).fill('界'.repeat(255)) },
    ]) {
        await assert.rejects(
            encryptMetadata(
                { ...base, ...change },
                vault.key,
                context,
                'opaque-1',
            ),
        );
    }
});
