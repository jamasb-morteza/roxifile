import type { MediaDetails } from './mediaMetadata';

export type VaultContext = { userId: number; connectionId: number };
type KeyWrap = { iv: string; ciphertext: string };
export type VaultEnvelope = {
    version: 1;
    salt: string;
    iterations: 600000;
    password: KeyWrap;
    recovery: KeyWrap;
};
export type StagedFile = { file: Blob; dispose: () => Promise<void> };

export type ProtectedMetadata = {
    name: string;
    mime: string;
    tags: string[];
    description: string;
    media?: MediaDetails;
};
export type MetadataEnvelope = {
    version: 1;
    salt: string;
    iv: string;
    ciphertext: string;
};

export function validateMediaDetails(details: MediaDetails): void {
    if (!details || typeof details !== 'object' || Array.isArray(details)) {
        throw new Error('Invalid track details.');
    }
    const limits = {
        title: 255,
        artist: 255,
        album: 255,
        genre: 255,
        lyrics: 10000,
    } as const;
    for (const [name, limit] of Object.entries(limits)) {
        const value = details[name as keyof typeof limits];
        if (
            value != null &&
            (typeof value !== 'string' || value.length > limit)
        )
            throw new Error('Invalid track details.');
    }
    if (
        details.track_number != null &&
        (!Number.isInteger(details.track_number) ||
            details.track_number < 1 ||
            details.track_number > 9999)
    ) {
        throw new Error('Invalid track number.');
    }
    for (const id of [details.cover_file_id, details.album_cover_file_id]) {
        if (id != null && (!Number.isSafeInteger(id) || id < 1))
            throw new Error('Invalid cover file.');
    }
}

function validateMetadata(value: ProtectedMetadata): void {
    const mimeWithSupportedWebmCodec =
        value?.mime === 'audio/webm;codecs=opus' ||
        value?.mime === 'video/webm;codecs=vp8';
    if (
        !value ||
        typeof value.name !== 'string' ||
        value.name.length < 1 ||
        value.name.length > 255 ||
        value.name.includes('/') ||
        ['.', '..'].includes(value.name) ||
        typeof value.mime !== 'string' ||
        value.mime.length > 255 ||
        (!/^[a-zA-Z0-9!#$&^_.+-]+\/[a-zA-Z0-9!#$&^_.+-]+$/.test(value.mime) &&
            !mimeWithSupportedWebmCodec) ||
        typeof value.description !== 'string' ||
        value.description.length > 10000 ||
        !Array.isArray(value.tags) ||
        value.tags.length > 100 ||
        value.tags.some((tag) => typeof tag !== 'string' || tag.length > 255)
    ) {
        throw new Error('Invalid protected metadata.');
    }
    if (value.media !== undefined) validateMediaDetails(value.media);
}

async function metadataKey(
    key: CryptoKey,
    salt: Uint8Array<ArrayBuffer>,
    label: string,
): Promise<CryptoKey> {
    return crypto.subtle.deriveKey(
        { name: 'HKDF', hash: 'SHA-256', salt, info: encoder.encode(label) },
        key,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt'],
    );
}

function metadataLabel(context: VaultContext, identity: string): string {
    if (!/^[a-zA-Z0-9_-]{1,128}$/.test(identity))
        throw new Error('Invalid protected file identity.');
    return `${contextLabel(context)}:metadata:1:${identity}`;
}

export async function encryptMetadata(
    value: ProtectedMetadata,
    key: CryptoKey,
    context: VaultContext,
    identity: string,
): Promise<MetadataEnvelope> {
    validateMetadata(value);
    const label = metadataLabel(context, identity);
    const salt = crypto.getRandomValues(new Uint8Array(32));
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const derived = await metadataKey(key, salt, label);
    const plaintext = encoder.encode(JSON.stringify(value));
    if (plaintext.byteLength > 74984) {
        plaintext.fill(0);
        throw new Error('Protected metadata is too large.');
    }
    try {
        const encrypted = await crypto.subtle.encrypt(
            {
                name: 'AES-GCM',
                iv,
                additionalData: encoder.encode(label),
                tagLength: 128,
            },
            derived,
            plaintext,
        );
        return {
            version: 1,
            salt: base64(salt),
            iv: base64(iv),
            ciphertext: base64(new Uint8Array(encrypted)),
        };
    } finally {
        plaintext.fill(0);
    }
}

export async function decryptMetadata(
    envelope: MetadataEnvelope,
    key: CryptoKey,
    context: VaultContext,
    identity: string,
): Promise<ProtectedMetadata> {
    if (
        envelope.version !== 1 ||
        typeof envelope.ciphertext !== 'string' ||
        envelope.ciphertext.length > 100000
    )
        throw new Error('Invalid protected metadata.');
    const label = metadataLabel(context, identity);
    const salt = unbase64(envelope.salt, 32);
    const iv = unbase64(envelope.iv, 12);
    const ciphertext = Uint8Array.from(atob(envelope.ciphertext), (char) =>
        char.charCodeAt(0),
    );
    const derived = await metadataKey(key, salt, label);
    const plaintext = new Uint8Array(
        await crypto.subtle.decrypt(
            {
                name: 'AES-GCM',
                iv,
                additionalData: encoder.encode(label),
                tagLength: 128,
            },
            derived,
            ciphertext,
        ),
    );
    try {
        const value = JSON.parse(
            new TextDecoder('utf-8', { fatal: true }).decode(plaintext),
        );
        validateMetadata(value);
        return value;
    } finally {
        plaintext.fill(0);
    }
}

const CHUNK_SIZE = 1024 * 1024;
const HEADER_SIZE = 64;
const MEMORY_LIMIT = 32 * 1024 * 1024;
const encoder = new TextEncoder();
const magic = encoder.encode('ROXIF001');

function base64(bytes: Uint8Array): string {
    return btoa(String.fromCharCode(...bytes));
}

function unbase64(value: string, length: number): Uint8Array<ArrayBuffer> {
    const bytes = Uint8Array.from(atob(value), (char) => char.charCodeAt(0));
    if (bytes.length !== length || base64(bytes) !== value) {
        throw new Error('Invalid vault material.');
    }
    return bytes;
}

function contextLabel(context: VaultContext): string {
    if (
        !Number.isSafeInteger(context.userId) ||
        context.userId < 1 ||
        !Number.isSafeInteger(context.connectionId) ||
        context.connectionId < 1
    ) {
        throw new Error('Invalid vault identity.');
    }
    return `roxifile:vault:1:${context.userId}:${context.connectionId}`;
}

async function passwordKey(
    password: string,
    salt: Uint8Array<ArrayBuffer>,
): Promise<CryptoKey> {
    const material = await crypto.subtle.importKey(
        'raw',
        encoder.encode(password),
        'PBKDF2',
        false,
        ['deriveKey'],
    );
    return crypto.subtle.deriveKey(
        { name: 'PBKDF2', hash: 'SHA-256', salt, iterations: 600000 },
        material,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt'],
    );
}

async function wrap(
    raw: Uint8Array<ArrayBuffer>,
    key: CryptoKey,
    label: string,
): Promise<KeyWrap> {
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const ciphertext = await crypto.subtle.encrypt(
        {
            name: 'AES-GCM',
            iv,
            additionalData: encoder.encode(label),
            tagLength: 128,
        },
        key,
        raw,
    );
    return { iv: base64(iv), ciphertext: base64(new Uint8Array(ciphertext)) };
}

export async function createVault(
    password: string,
    context: VaultContext,
): Promise<{
    envelope: VaultEnvelope;
    key: CryptoKey;
    recoverySecret: string;
}> {
    const label = contextLabel(context);
    if (password.length < 12)
        throw new Error(
            'Use at least 12 characters for the separate vault password.',
        );
    const raw = crypto.getRandomValues(new Uint8Array(32));
    const recovery = crypto.getRandomValues(new Uint8Array(32));
    const salt = crypto.getRandomValues(new Uint8Array(16));
    try {
        const recoveryKey = await crypto.subtle.importKey(
            'raw',
            recovery,
            'AES-GCM',
            false,
            ['encrypt'],
        );
        return {
            key: await crypto.subtle.importKey('raw', raw, 'HKDF', false, [
                'deriveKey',
            ]),
            recoverySecret: base64(recovery),
            envelope: {
                version: 1,
                salt: base64(salt),
                iterations: 600000,
                password: await wrap(
                    raw,
                    await passwordKey(password, salt),
                    `${label}:password`,
                ),
                recovery: await wrap(raw, recoveryKey, `${label}:recovery`),
            },
        };
    } finally {
        raw.fill(0);
        recovery.fill(0);
    }
}

async function unwrapVault(
    envelope: VaultEnvelope,
    credential: string,
    context: VaultContext,
    recovery = false,
): Promise<Uint8Array<ArrayBuffer>> {
    const label = contextLabel(context);
    if (envelope.version !== 1 || envelope.iterations !== 600000)
        throw new Error('Unsupported vault format.');
    const salt = unbase64(envelope.salt, 16);
    let recoveryBytes: Uint8Array<ArrayBuffer> | undefined;
    try {
        if (recovery) recoveryBytes = unbase64(credential.trim(), 32);
        const key = recovery
            ? await crypto.subtle.importKey(
                  'raw',
                  recoveryBytes!,
                  'AES-GCM',
                  false,
                  ['decrypt'],
              )
            : await passwordKey(credential, salt);
        const wrapped = recovery ? envelope.recovery : envelope.password;
        const raw = new Uint8Array(
            await crypto.subtle.decrypt(
                {
                    name: 'AES-GCM',
                    iv: unbase64(wrapped.iv, 12),
                    tagLength: 128,
                    additionalData: encoder.encode(
                        `${label}:${recovery ? 'recovery' : 'password'}`,
                    ),
                },
                key,
                unbase64(wrapped.ciphertext, 48),
            ),
        );
        return raw;
    } catch {
        throw new Error(
            'Could not unlock: wrong credential or damaged vault material.',
        );
    } finally {
        recoveryBytes?.fill(0);
    }
}

export async function unlockVault(
    envelope: VaultEnvelope,
    credential: string,
    context: VaultContext,
    recovery = false,
): Promise<CryptoKey> {
    const raw = await unwrapVault(envelope, credential, context, recovery);
    try {
        return await crypto.subtle.importKey('raw', raw, 'HKDF', false, [
            'deriveKey',
        ]);
    } finally {
        raw.fill(0);
    }
}

export async function changeVaultPassword(
    envelope: VaultEnvelope,
    credential: string,
    newPassword: string,
    context: VaultContext,
    recovery = false,
): Promise<VaultEnvelope> {
    if (newPassword.length < 12) {
        throw new Error(
            'Use at least 12 characters for the separate vault password.',
        );
    }
    const raw = await unwrapVault(envelope, credential, context, recovery);
    const salt = crypto.getRandomValues(new Uint8Array(16));
    try {
        return {
            ...envelope,
            salt: base64(salt),
            password: await wrap(
                raw,
                await passwordKey(newPassword, salt),
                `${contextLabel(context)}:password`,
            ),
        };
    } finally {
        raw.fill(0);
    }
}

export type RecoveryKit = VaultContext & {
    format: 'roxifile-recovery-v1';
    recoverySecret: string;
    envelope: VaultEnvelope;
};

/** Replaces the recovery wrap; the previous secret cannot be recovered from a password. */
export async function createRecoveryKit(
    envelope: VaultEnvelope,
    credential: string,
    context: VaultContext,
    recovery = false,
): Promise<RecoveryKit> {
    const raw = await unwrapVault(envelope, credential, context, recovery);
    const secret = crypto.getRandomValues(new Uint8Array(32));
    try {
        const key = await crypto.subtle.importKey(
            'raw',
            secret,
            'AES-GCM',
            false,
            ['encrypt'],
        );
        return {
            format: 'roxifile-recovery-v1',
            ...context,
            recoverySecret: base64(secret),
            envelope: {
                ...envelope,
                recovery: await wrap(
                    raw,
                    key,
                    `${contextLabel(context)}:recovery`,
                ),
            },
        };
    } finally {
        raw.fill(0);
        secret.fill(0);
    }
}

export function readRecoveryKit(
    contents: string,
    context: VaultContext,
): string {
    try {
        const kit = JSON.parse(contents);
        if (
            kit.format !== 'roxifile-recovery-v1' ||
            kit.userId !== context.userId ||
            kit.connectionId !== context.connectionId
        ) {
            throw new Error();
        }
        unbase64(kit.recoverySecret, 32).fill(0);
        return kit.recoverySecret;
    } catch {
        throw new Error(
            'Invalid recovery file or it belongs to another account or connection.',
        );
    }
}

export function encryptedSize(size: number): number {
    if (
        !Number.isSafeInteger(size) ||
        size < 0 ||
        Math.ceil(size / CHUNK_SIZE) > 0xffffffff
    ) {
        throw new Error('File size is unsupported.');
    }
    return HEADER_SIZE + size + Math.max(1, Math.ceil(size / CHUNK_SIZE)) * 16;
}

function headerFor(
    size: number,
    context: VaultContext,
): Uint8Array<ArrayBuffer> {
    encryptedSize(size);
    const header = new Uint8Array(HEADER_SIZE);
    header.set(magic);
    crypto.getRandomValues(header.subarray(8, 40));
    const view = new DataView(header.buffer);
    view.setBigUint64(40, BigInt(size));
    view.setBigUint64(48, BigInt(context.connectionId));
    view.setUint32(56, CHUNK_SIZE);
    return header;
}

function parseHeader(
    header: Uint8Array<ArrayBuffer>,
    context: VaultContext,
): number {
    if (
        header.length !== HEADER_SIZE ||
        !magic.every((value, i) => header[i] === value)
    ) {
        throw new Error('Unsupported encrypted file format.');
    }
    const view = new DataView(
        header.buffer,
        header.byteOffset,
        header.byteLength,
    );
    const size = Number(view.getBigUint64(40));
    encryptedSize(size);
    if (
        view.getBigUint64(48) !== BigInt(context.connectionId) ||
        view.getUint32(56) !== CHUNK_SIZE ||
        view.getUint32(60) !== 0
    ) {
        throw new Error('Invalid encrypted file header.');
    }
    return size;
}

async function fileKey(
    key: CryptoKey,
    header: Uint8Array<ArrayBuffer>,
    context: VaultContext,
): Promise<CryptoKey> {
    return crypto.subtle.deriveKey(
        {
            name: 'HKDF',
            hash: 'SHA-256',
            salt: header.slice(8, 40),
            info: encoder.encode(`${contextLabel(context)}:contents:1`),
        },
        key,
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt'],
    );
}

function chunkParameters(
    header: Uint8Array<ArrayBuffer>,
    index: number,
): AesGcmParams {
    const iv = new Uint8Array(12);
    new DataView(iv.buffer).setUint32(8, index);
    const additionalData = new Uint8Array(HEADER_SIZE + 4);
    additionalData.set(header);
    new DataView(additionalData.buffer).setUint32(HEADER_SIZE, index);
    return { name: 'AES-GCM', iv, additionalData, tagLength: 128 };
}

/** OPFS stages large transfers; browsers without OPFS get an explicit bounded fallback. */
async function staging(
    size: number,
    usePrivateStorage = true,
): Promise<{
    write: (bytes: Uint8Array<ArrayBuffer>) => Promise<void>;
    finish: () => Promise<Blob>;
    dispose: () => Promise<void>;
}> {
    if (
        usePrivateStorage &&
        typeof navigator !== 'undefined' &&
        navigator.storage?.getDirectory
    ) {
        const root = await navigator.storage.getDirectory();
        const name = `roxifile-transfer-${crypto.randomUUID()}`;
        const handle = await root.getFileHandle(name, { create: true });
        let writer: FileSystemWritableFileStream;
        try {
            writer = await handle.createWritable();
        } catch (error) {
            await root.removeEntry(name);
            throw error;
        }
        let closed = false;
        return {
            write: async (bytes) => {
                await writer.write(bytes);
            },
            finish: async () => {
                await writer.close();
                closed = true;
                return handle.getFile();
            },
            dispose: async () => {
                if (!closed) await writer.abort().catch(() => {});
                await root.removeEntry(name).catch(() => {});
            },
        };
    }
    if (size > MEMORY_LIMIT)
        throw new Error(
            'This browser needs private file storage for transfers larger than 32 MiB.',
        );
    const parts: Uint8Array<ArrayBuffer>[] = [];
    return {
        write: async (bytes) => {
            parts.push(bytes);
        },
        finish: async () =>
            new Blob(parts, { type: 'application/octet-stream' }),
        dispose: async () => {
            parts.length = 0;
        },
    };
}

export async function encryptFile(
    input: Blob,
    key: CryptoKey,
    context: VaultContext,
    signal?: AbortSignal,
): Promise<StagedFile> {
    const header = headerFor(input.size, context);
    const derived = await fileKey(key, header, context);
    const sink = await staging(encryptedSize(input.size));
    try {
        await sink.write(header);
        for (
            let index = 0;
            index < Math.max(1, Math.ceil(input.size / CHUNK_SIZE));
            index++
        ) {
            signal?.throwIfAborted();
            const bytes = await input
                .slice(index * CHUNK_SIZE, (index + 1) * CHUNK_SIZE)
                .arrayBuffer();
            const result = await crypto.subtle.encrypt(
                chunkParameters(header, index),
                derived,
                bytes,
            );
            await sink.write(new Uint8Array(result));
        }
        signal?.throwIfAborted();
        return { file: await sink.finish(), dispose: sink.dispose };
    } catch (error) {
        await sink.dispose();
        throw error;
    }
}

export async function decryptFile(
    input: Blob,
    key: CryptoKey,
    context: VaultContext,
    signal?: AbortSignal,
    expectedHeader?: string,
    destination?: FileSystemWritableFileStream,
): Promise<StagedFile> {
    const header = new Uint8Array(
        await input.slice(0, HEADER_SIZE).arrayBuffer(),
    );
    const size = parseHeader(header, context);
    if (
        input.size !== encryptedSize(size) ||
        (expectedHeader && base64(header) !== expectedHeader)
    ) {
        throw new Error(
            'The encrypted file is incomplete or does not match this file.',
        );
    }
    const derived = await fileKey(key, header, context);
    const abortDestination = (): void => {
        if (destination) void destination.abort().catch(() => {});
    };
    const sink = destination
        ? {
              write: async (bytes: Uint8Array<ArrayBuffer>) => {
                  await destination.write(bytes);
              },
              finish: async () => {
                  signal?.throwIfAborted();
                  signal?.addEventListener('abort', abortDestination, {
                      once: true,
                  });
                  try {
                      await destination.close();
                      signal?.throwIfAborted();
                      return new Blob([]);
                  } finally {
                      signal?.removeEventListener('abort', abortDestination);
                  }
              },
              dispose: async () => {
                  signal?.removeEventListener('abort', abortDestination);
                  await destination.abort().catch(() => {});
              },
          }
        : await staging(size, false);
    try {
        for (
            let index = 0;
            index < Math.max(1, Math.ceil(size / CHUNK_SIZE));
            index++
        ) {
            signal?.throwIfAborted();
            const offset = HEADER_SIZE + index * (CHUNK_SIZE + 16);
            const bytes = await input
                .slice(
                    offset,
                    offset +
                        Math.min(CHUNK_SIZE, size - index * CHUNK_SIZE) +
                        16,
                )
                .arrayBuffer();
            const plaintext = await crypto.subtle.decrypt(
                chunkParameters(header, index),
                derived,
                bytes,
            );
            await sink.write(new Uint8Array(plaintext));
        }
        signal?.throwIfAborted();
        return { file: await sink.finish(), dispose: sink.dispose };
    } catch (error) {
        await sink.dispose();
        throw error;
    }
}

/** Decrypt one authenticated ROXIF001 chunk at a time for bounded media playback. */
export async function decryptFileStream(
    input: Blob,
    key: CryptoKey,
    context: VaultContext,
    signal?: AbortSignal,
    expectedHeader?: string,
): Promise<ReadableStream<Uint8Array<ArrayBuffer>>> {
    const header = new Uint8Array(
        await input.slice(0, HEADER_SIZE).arrayBuffer(),
    );
    const size = parseHeader(header, context);
    if (
        input.size !== encryptedSize(size) ||
        (expectedHeader && base64(header) !== expectedHeader)
    ) {
        throw new Error(
            'The encrypted file is incomplete or does not match this file.',
        );
    }
    const derived = await fileKey(key, header, context);
    const chunkCount = Math.max(1, Math.ceil(size / CHUNK_SIZE));
    let index = 0;
    let cancelled = false;
    return new ReadableStream<Uint8Array<ArrayBuffer>>(
        {
            pull: async (controller) => {
                try {
                    if (cancelled) return;
                    signal?.throwIfAborted();
                    if (index >= chunkCount) {
                        controller.close();
                        return;
                    }
                    const offset = HEADER_SIZE + index * (CHUNK_SIZE + 16);
                    const bytes = await input
                        .slice(
                            offset,
                            offset +
                                Math.min(
                                    CHUNK_SIZE,
                                    size - index * CHUNK_SIZE,
                                ) +
                                16,
                        )
                        .arrayBuffer();
                    const plaintext = await crypto.subtle.decrypt(
                        chunkParameters(header, index),
                        derived,
                        bytes,
                    );
                    if (cancelled) return;
                    signal?.throwIfAborted();
                    controller.enqueue(new Uint8Array(plaintext));
                    index++;
                    if (index === chunkCount) controller.close();
                } catch (error) {
                    if (!cancelled) controller.error(error);
                }
            },
            cancel: () => {
                cancelled = true;
            },
        },
        { highWaterMark: 1 },
    );
}

/** Stage the network response without assembling an entire large download in JS memory. */
export async function stageDownload(
    response: Response,
    expectedSize: number,
    signal?: AbortSignal,
): Promise<StagedFile> {
    if (!response.ok || !response.body)
        throw new Error(
            'The download expired or is unavailable. Prepare it again.',
        );
    const sink = await staging(expectedSize);
    const reader = response.body.getReader();
    let received = 0;
    try {
        while (true) {
            signal?.throwIfAborted();
            const { done, value } = await reader.read();
            if (done) break;
            received += value.byteLength;
            if (received > expectedSize)
                throw new Error('Unexpected encrypted download size.');
            await sink.write(new Uint8Array(value));
        }
        if (received !== expectedSize)
            throw new Error('The download is incomplete.');
        return { file: await sink.finish(), dispose: sink.dispose };
    } catch (error) {
        await reader.cancel().catch(() => {});
        await sink.dispose();
        throw error;
    } finally {
        reader.releaseLock();
    }
}

export type ProtectedFileEntry = {
    id: number;
    connection_id: number | null;
    storage_name: string;
    metadata_protected: boolean;
    protected_metadata: MetadataEnvelope | null;
    name: string;
    mime_type: string;
    file_type: string;
    description: string | null;
    directory_path: string;
    tags: { id: number; name: string }[];
    metadata_unlocked?: boolean;
};

export function metadataFileType(mime: string): string {
    if (mime === 'application/pdf') return 'pdf';
    for (const kind of ['image', 'video', 'audio']) {
        if (mime.startsWith(`${kind}/`)) return kind;
    }
    if (mime === 'text/csv' || /spreadsheet|excel/.test(mime))
        return 'spreadsheet';
    if (/presentation|powerpoint/.test(mime)) return 'presentation';
    if (/word|rtf|opendocument.text/.test(mime)) return 'document';
    if (/zip|rar|tar|gzip|archive/.test(mime)) return 'archive';
    if (
        mime.startsWith('text/') ||
        /json|xml|javascript|x-php|x-sh|yaml/.test(mime)
    )
        return 'text';
    return 'other';
}

export async function unlockFileMetadata<T extends ProtectedFileEntry>(
    file: T,
    key: CryptoKey,
    context: VaultContext,
): Promise<T> {
    if (
        !file.metadata_protected ||
        !file.protected_metadata ||
        file.connection_id !== context.connectionId
    ) {
        throw new Error(
            'Protected metadata is missing or belongs to another connection.',
        );
    }
    const metadata = await decryptMetadata(
        file.protected_metadata,
        key,
        context,
        file.storage_name,
    );
    return {
        ...file,
        name: metadata.name,
        mime_type: metadata.mime,
        file_type: metadataFileType(metadata.mime),
        description: metadata.description,
        tags: metadata.tags.map((name, index) => ({ id: index, name })),
        metadata_unlocked: true,
    };
}

export function filterProtectedFiles<T extends ProtectedFileEntry>(
    files: T[],
    query: string,
    tag: string,
    type: string,
    mime: string,
): T[] {
    const needle = query.trim().toLocaleLowerCase();
    return files.filter(
        (file) =>
            file.metadata_unlocked &&
            (tag === 'all' || file.tags.some((item) => item.name === tag)) &&
            (type === 'all' || file.file_type === type) &&
            (!mime || file.mime_type === mime) &&
            (!needle ||
                [
                    file.name,
                    file.description ?? '',
                    file.mime_type,
                    file.directory_path,
                    ...file.tags.map((item) => item.name),
                ].some((value) => value.toLocaleLowerCase().includes(needle))),
    );
}
