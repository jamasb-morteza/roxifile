import test from 'node:test';
import assert from 'node:assert/strict';
import { createCipheriv, hkdfSync } from 'node:crypto';
import {
    createVault,
    unlockVault,
    encryptFile,
    decryptFile,
    decryptFileStream,
    stageDownload,
    encryptMetadata,
    decryptMetadata,
} from '../../resources/js/lib/contentsEncryption.ts';

void test('large encrypted media decrypts as authenticated bounded chunks', async () => {
    const context = { userId: 7, connectionId: 19 };
    const size = 32 * 1024 * 1024 + 17;
    const raw = Buffer.alloc(32, 0x6b);
    const header = Buffer.alloc(64);
    header.write('ROXIF001');
    header.fill(0x73, 8, 40);
    header.writeBigUInt64BE(BigInt(size), 40);
    header.writeBigUInt64BE(BigInt(context.connectionId), 48);
    header.writeUInt32BE(1048576, 56);
    const derived = hkdfSync(
        'sha256',
        raw,
        header.subarray(8, 40),
        'roxifile:vault:1:7:19:contents:1',
        32,
    );
    const chunks = [];
    for (let index = 0; index < Math.ceil(size / 1048576); index++) {
        const length = Math.min(1048576, size - index * 1048576);
        const iv = Buffer.alloc(12);
        iv.writeUInt32BE(index, 8);
        const aad = Buffer.alloc(68);
        header.copy(aad);
        aad.writeUInt32BE(index, 64);
        const cipher = createCipheriv('aes-256-gcm', derived, iv);
        cipher.setAAD(aad);
        chunks.push(
            Buffer.concat([
                cipher.update(Buffer.alloc(length, 42)),
                cipher.final(),
                cipher.getAuthTag(),
            ]),
        );
    }
    const encryptedSize =
        64 + chunks.reduce((total, chunk) => total + chunk.length, 0);
    const input = {
        size: encryptedSize,
        slice(start, end) {
            let offset = 0;
            const selected = [];
            for (const part of [header, ...chunks]) {
                const partEnd = offset + part.length;
                if (partEnd > start && offset < end) {
                    selected.push(
                        part.subarray(
                            Math.max(0, start - offset),
                            Math.min(part.length, end - offset),
                        ),
                    );
                }
                offset = partEnd;
            }
            const bytes = Buffer.concat(selected);
            return {
                arrayBuffer: async () =>
                    bytes.buffer.slice(
                        bytes.byteOffset,
                        bytes.byteOffset + bytes.byteLength,
                    ),
            };
        },
    };
    const key = await crypto.subtle.importKey('raw', raw, 'HKDF', false, [
        'deriveKey',
    ]);
    const stream = await decryptFileStream(
        input,
        key,
        context,
        undefined,
        header.toString('base64'),
    );
    const reader = stream.getReader();
    let total = 0;
    while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        assert.ok(value.byteLength <= 1048576);
        assert.equal(value[0], 42);
        total += value.byteLength;
    }
    assert.equal(total, size);

    const wrongKey = await crypto.subtle.importKey(
        'raw',
        Buffer.alloc(32),
        'HKDF',
        false,
        ['deriveKey'],
    );
    const rejected = await decryptFileStream(input, wrongKey, context);
    await assert.rejects(rejected.getReader().read());
    const abort = new AbortController();
    abort.abort();
    const aborted = await decryptFileStream(input, key, context, abort.signal);
    await assert.rejects(aborted.getReader().read(), { name: 'AbortError' });

    const tampered = {
        ...input,
        slice(start, end) {
            const sliced = input.slice(start, end);
            return {
                arrayBuffer: async () => {
                    const bytes = new Uint8Array(await sliced.arrayBuffer());
                    if (start === 64 + 1048576 + 16) bytes[0] ^= 1;
                    return bytes.buffer;
                },
            };
        },
    };
    const unauthenticated = await decryptFileStream(tampered, key, context);
    const unauthenticatedReader = unauthenticated.getReader();
    assert.equal(
        (await unauthenticatedReader.read()).value.byteLength,
        1048576,
    );
    await assert.rejects(unauthenticatedReader.read());
});

void test('protected metadata authenticates its account, connection and opaque identity', async () => {
    const context = { userId: 7, connectionId: 19 };
    const vault = await createVault(
        'a separate strong vault password',
        context,
    );
    const metadata = {
        name: 'private <report>.pdf',
        mime: 'application/pdf',
        tags: ['private'],
        description: 'confidential description',
    };
    const sealed = await encryptMetadata(
        metadata,
        vault.key,
        context,
        'opaque-file-1',
    );
    assert.equal(JSON.stringify(sealed).includes('private'), false);
    assert.deepEqual(
        await decryptMetadata(sealed, vault.key, context, 'opaque-file-1'),
        metadata,
    );
    for (const mime of ['audio/webm;codecs=opus', 'video/webm;codecs=vp8']) {
        const details = {
            ...metadata,
            mime,
            media: {
                title: 'Vault track',
                lyrics: 'Private lyrics',
                cover_file_id: 42,
            },
        };
        const sealedTrack = await encryptMetadata(
            details,
            vault.key,
            context,
            'opaque-media',
        );
        assert.equal(
            JSON.stringify(sealedTrack).includes('Private lyrics'),
            false,
        );
        assert.deepEqual(
            await decryptMetadata(
                sealedTrack,
                vault.key,
                context,
                'opaque-media',
            ),
            details,
        );
    }
    for (const [identity, file] of [
        [{ userId: 8, connectionId: 19 }, 'opaque-file-1'],
        [{ userId: 7, connectionId: 20 }, 'opaque-file-1'],
        [context, 'opaque-file-2'],
    ]) {
        await assert.rejects(
            decryptMetadata(sealed, vault.key, identity, file),
        );
    }
    await assert.rejects(
        decryptMetadata(
            { ...sealed, ciphertext: sealed.ciphertext.slice(4) },
            vault.key,
            context,
            'opaque-file-1',
        ),
    );
    const second = await encryptMetadata(
        metadata,
        vault.key,
        context,
        'opaque-file-1',
    );
    assert.notDeepEqual(second, sealed);
});

void test('vault password and recovery wraps unlock actual chunked file bytes', async () => {
    const context = { userId: 7, connectionId: 19 };
    const vault = await createVault(
        'a separate strong vault password',
        context,
    );
    const key = await unlockVault(
        vault.envelope,
        'a separate strong vault password',
        context,
    );
    const original = new Blob([new Uint8Array(1048577).fill(42)]);
    const encrypted = await encryptFile(original, key, context);
    assert.equal(encrypted.file.size, 1048673);
    assert.notDeepEqual(
        await encrypted.file.arrayBuffer(),
        await original.arrayBuffer(),
    );
    const recovered = await unlockVault(
        vault.envelope,
        vault.recoverySecret,
        context,
        true,
    );
    const decrypted = await decryptFile(encrypted.file, recovered, context);
    assert.deepEqual(
        await decrypted.file.arrayBuffer(),
        await original.arrayBuffer(),
    );
    await decrypted.dispose();
    await encrypted.dispose();
});

void test('wrong passwords, damaged wraps and foreign identities fail closed', async () => {
    const context = { userId: 7, connectionId: 19 };
    const vault = await createVault(
        'a separate strong vault password',
        context,
    );
    await assert.rejects(
        unlockVault(vault.envelope, 'incorrect password', context),
    );
    await assert.rejects(
        unlockVault(vault.envelope, 'a separate strong vault password', {
            ...context,
            userId: 8,
        }),
    );
    await assert.rejects(
        unlockVault(vault.envelope, 'a separate strong vault password', {
            ...context,
            connectionId: 20,
        }),
    );
    const corrupt = structuredClone(vault.envelope);
    corrupt.password.ciphertext = 'A'.repeat(64);
    await assert.rejects(
        unlockVault(corrupt, 'a separate strong vault password', context),
    );
    await assert.rejects(
        unlockVault(
            { ...vault.envelope, iterations: 1 },
            'a separate strong vault password',
            context,
        ),
    );
    await assert.rejects(
        unlockVault(vault.envelope, 'invalid recovery secret', context, true),
    );
});

void test('matches an independently computed OpenSSL AES-GCM/HKDF vector', async () => {
    const context = { userId: 7, connectionId: 19 };
    const raw = Buffer.alloc(32, 0x6b);
    const header = Buffer.alloc(64);
    header.write('ROXIF001');
    header.fill(0x73, 8, 40);
    header.writeBigUInt64BE(5n, 40);
    header.writeBigUInt64BE(19n, 48);
    header.writeUInt32BE(1048576, 56);
    const derived = hkdfSync(
        'sha256',
        raw,
        header.subarray(8, 40),
        'roxifile:vault:1:7:19:contents:1',
        32,
    );
    const cipher = createCipheriv('aes-256-gcm', derived, Buffer.alloc(12));
    cipher.setAAD(Buffer.concat([header, Buffer.alloc(4)]));
    const fixture = Buffer.concat([
        header,
        cipher.update('hello'),
        cipher.final(),
        cipher.getAuthTag(),
    ]);
    const key = await crypto.subtle.importKey('raw', raw, 'HKDF', false, [
        'deriveKey',
    ]);
    const result = await decryptFile(new Blob([fixture]), key, context);
    assert.equal(await result.file.text(), 'hello');
    await result.dispose();
});

void test('empty files authenticate and all ciphertext edits, reordering and truncation are rejected', async () => {
    const context = { userId: 1, connectionId: 2 };
    const key = await crypto.subtle.importKey(
        'raw',
        crypto.getRandomValues(new Uint8Array(32)),
        'HKDF',
        false,
        ['deriveKey'],
    );
    const empty = await encryptFile(new Blob([]), key, context);
    assert.equal(empty.file.size, 80);
    const decrypted = await decryptFile(empty.file, key, context);
    assert.equal(decrypted.file.size, 0);
    await decrypted.dispose();
    await empty.dispose();
    const encrypted = await encryptFile(
        new Blob([new Uint8Array(1048577).fill(42)]),
        key,
        context,
    );
    const bytes = new Uint8Array(await encrypted.file.arrayBuffer());
    for (const offset of [0, 8, 40, 48, 56, 60, 64, bytes.length - 1]) {
        const damaged = bytes.slice();
        damaged[offset] ^= 1;
        await assert.rejects(decryptFile(new Blob([damaged]), key, context));
    }
    for (const length of [0, 63, 64, bytes.length - 1, bytes.length - 17]) {
        await assert.rejects(
            decryptFile(encrypted.file.slice(0, length), key, context),
        );
    }
    await assert.rejects(
        decryptFile(new Blob([bytes, new Uint8Array([0])]), key, context),
    );
    const swapped = new Blob([
        bytes.slice(0, 64),
        bytes.slice(64 + 1048592),
        bytes.slice(64, 64 + 1048592),
    ]);
    await assert.rejects(decryptFile(swapped, key, context));
    const other = await encryptFile(
        new Blob(['another valid file']),
        key,
        context,
    );
    await assert.rejects(
        decryptFile(
            other.file,
            key,
            context,
            undefined,
            Buffer.from(bytes.slice(0, 64)).toString('base64'),
        ),
    );
    await assert.rejects(
        decryptFile(encrypted.file, key, { ...context, userId: 9 }),
    );
    const wrongKey = await crypto.subtle.importKey(
        'raw',
        new Uint8Array(32),
        'HKDF',
        false,
        ['deriveKey'],
    );
    await assert.rejects(decryptFile(encrypted.file, wrongKey, context));
    await encrypted.dispose();
    await other.dispose();
});

void test('cancellation and missing large-file storage never return a successful file', async () => {
    const context = { userId: 1, connectionId: 2 };
    const key = await crypto.subtle.importKey(
        'raw',
        new Uint8Array(32),
        'HKDF',
        false,
        ['deriveKey'],
    );
    const abort = new AbortController();
    abort.abort();
    await assert.rejects(
        encryptFile(new Blob(['cancelled']), key, context, abort.signal),
        {
            name: 'AbortError',
        },
    );
    const encrypted = await encryptFile(new Blob(['cancelled']), key, context);
    await assert.rejects(
        decryptFile(encrypted.file, key, context, abort.signal),
        {
            name: 'AbortError',
        },
    );
    await assert.rejects(
        encryptFile(new Blob([new Uint8Array(33 * 1024 * 1024)]), key, context),
        /private file storage/,
    );
    await encrypted.dispose();
});

void test('network staging rejects incomplete responses and bounds incoming data', async () => {
    const staged = await stageDownload(new Response('ciphertext'), 10);
    assert.equal(await staged.file.text(), 'ciphertext');
    await staged.dispose();
    await assert.rejects(
        stageDownload(new Response('short'), 10),
        /incomplete/,
    );
    await assert.rejects(
        stageDownload(new Response('too many bytes'), 2),
        /Unexpected/,
    );
    await assert.rejects(
        stageDownload(new Response('', { status: 410 }), 0),
        /expired/,
    );
    const abort = new AbortController();
    abort.abort();
    await assert.rejects(
        stageDownload(new Response('ciphertext'), 10, abort.signal),
        {
            name: 'AbortError',
        },
    );
});

void test('streamed exports commit only after authentication and abort on tampering or write failure', async () => {
    const context = { userId: 1, connectionId: 2 };
    const key = await crypto.subtle.importKey(
        'raw',
        new Uint8Array(32),
        'HKDF',
        false,
        ['deriveKey'],
    );
    const encrypted = await encryptFile(
        new Blob(['authenticated export']),
        key,
        context,
    );
    let committed = false;
    let aborted = false;
    const output = [];
    const destination = {
        write: async (bytes) => {
            output.push(bytes);
        },
        close: async () => {
            committed = true;
        },
        abort: async () => {
            aborted = true;
            output.length = 0;
        },
    };
    await decryptFile(
        encrypted.file,
        key,
        context,
        undefined,
        undefined,
        destination,
    );
    assert.equal(await new Blob(output).text(), 'authenticated export');
    assert.equal(committed, true);
    committed = false;
    const bytes = new Uint8Array(await encrypted.file.arrayBuffer());
    bytes[bytes.length - 1] ^= 1;
    await assert.rejects(
        decryptFile(
            new Blob([bytes]),
            key,
            context,
            undefined,
            undefined,
            destination,
        ),
    );
    assert.equal(committed, false);
    assert.equal(aborted, true);
    aborted = false;
    await assert.rejects(
        decryptFile(encrypted.file, key, context, undefined, undefined, {
            ...destination,
            write: async () => {
                throw new Error('disk full');
            },
        }),
        /disk full/,
    );
    assert.equal(committed, false);
    assert.equal(aborted, true);
    await encrypted.dispose();
});

void test('fresh devices can change the vault password through password or recovery without changing file keys', async () => {
    const { changeVaultPassword } =
        await import('../../resources/js/lib/contentsEncryption.ts');
    const context = { userId: 7, connectionId: 19 };
    const originalPassword = 'original separate vault password';
    const vault = await createVault(originalPassword, context);
    const encrypted = await encryptFile(
        new Blob(['saved before password change']),
        vault.key,
        context,
    );
    let envelope = JSON.parse(JSON.stringify(vault.envelope));
    for (const recovery of [false, true]) {
        const nextPassword = `replacement password ${recovery}`;
        envelope = await changeVaultPassword(
            envelope,
            recovery ? vault.recoverySecret : originalPassword,
            nextPassword,
            context,
            recovery,
        );
        assert.deepEqual(envelope.recovery, vault.envelope.recovery);
        assert.notEqual(envelope.salt, vault.envelope.salt);
        const fresh = JSON.parse(JSON.stringify(envelope));
        for (const [credential, useRecovery] of [
            [nextPassword, false],
            [vault.recoverySecret, true],
        ]) {
            const key = await unlockVault(
                fresh,
                credential,
                context,
                useRecovery,
            );
            assert.equal(key.extractable, false);
            const output = await decryptFile(encrypted.file, key, context);
            assert.equal(
                await output.file.text(),
                'saved before password change',
            );
            await output.dispose();
        }
        await assert.rejects(unlockVault(envelope, originalPassword, context));
    }
    const oldKey = await unlockVault(vault.envelope, originalPassword, context);
    const oldCopy = await decryptFile(encrypted.file, oldKey, context);
    assert.equal(await oldCopy.file.text(), 'saved before password change');
    await oldCopy.dispose();
    await encrypted.dispose();
});

void test('password changes fail honestly for wrong credentials, short passwords and corrupt or foreign wraps', async () => {
    const { changeVaultPassword } =
        await import('../../resources/js/lib/contentsEncryption.ts');
    const context = { userId: 7, connectionId: 19 };
    const password = 'separate vault password';
    const vault = await createVault(password, context);
    const before = structuredClone(vault.envelope);
    await assert.rejects(
        changeVaultPassword(
            before,
            'wrong password',
            'replacement vault password',
            context,
        ),
    );
    await assert.rejects(
        changeVaultPassword(before, password, 'short', context),
    );
    await assert.rejects(
        changeVaultPassword(before, password, 'replacement vault password', {
            ...context,
            userId: 8,
        }),
    );
    await assert.rejects(
        changeVaultPassword(
            before,
            '',
            'replacement vault password',
            context,
            true,
        ),
    );
    await assert.rejects(
        changeVaultPassword(
            {
                ...before,
                recovery: { ...before.recovery, ciphertext: 'A'.repeat(64) },
            },
            vault.recoverySecret,
            'replacement vault password',
            context,
            true,
        ),
    );
    assert.deepEqual(before, vault.envelope);
});

void test('replacement recovery kits recover existing bytes and reject damaged or mismatched imports', async () => {
    const { createRecoveryKit, readRecoveryKit } =
        await import('../../resources/js/lib/contentsEncryption.ts');
    const context = { userId: 7, connectionId: 19 };
    const password = 'separate vault password';
    const vault = await createVault(password, context);
    const encrypted = await encryptFile(
        new Blob(['recover me']),
        vault.key,
        context,
    );
    const kit = await createRecoveryKit(vault.envelope, password, context);
    assert.deepEqual(kit.envelope.password, vault.envelope.password);
    assert.equal(kit.envelope.salt, vault.envelope.salt);
    const imported = readRecoveryKit(JSON.stringify(kit), context);
    const recovered = await unlockVault(kit.envelope, imported, context, true);
    const output = await decryptFile(encrypted.file, recovered, context);
    assert.equal(await output.file.text(), 'recover me');
    await assert.rejects(
        unlockVault(kit.envelope, vault.recoverySecret, context, true),
    );
    await assert.rejects(createRecoveryKit(vault.envelope, 'wrong', context));
    assert.throws(() => readRecoveryKit('{', context));
    assert.throws(() =>
        readRecoveryKit(JSON.stringify({ ...kit, userId: 8 }), context),
    );
    assert.throws(() =>
        readRecoveryKit(JSON.stringify({ ...kit, connectionId: 20 }), context),
    );
    assert.throws(() =>
        readRecoveryKit(
            JSON.stringify({ ...kit, recoverySecret: '' }),
            context,
        ),
    );
    await output.dispose();
    await encrypted.dispose();
});
