import type { MetadataEnvelope, VaultEnvelope } from './contentsEncryption';

export type BrowserSnapshot = {
    id: number;
    name: string;
    storage_name: string;
    mime_type: string;
    size: number;
    directory_path: string;
    description: string | null;
    tags: { id: number; name: string }[];
    connection_id: number | null;
    encryption_mode: 'none' | 'contents' | 'contents_and_metadata';
    encryption_header: string | null;
    metadata_protected: boolean;
    protected_metadata: MetadataEnvelope | null;
    vault_envelope: VaultEnvelope | null;
};
export type BrowserCopy = BrowserSnapshot & {
    account: number;
    path: string;
    saved_at: string;
    original_missing: boolean;
    available?: boolean;
};
const accountKey = 'roxifile.browser-account';
const changed = 'roxifile-browser-copies';
const active = new Set<AbortController>();
export const offlineShell = '/build/resources/offline.html';

function safeKeyWrap(
    value: unknown,
): { iv: string; ciphertext: string } | null {
    if (
        !value ||
        typeof value !== 'object' ||
        typeof (value as { iv?: unknown }).iv !== 'string' ||
        typeof (value as { ciphertext?: unknown }).ciphertext !== 'string'
    ) {
        return null;
    }
    const wrap = value as { iv: string; ciphertext: string };
    if (wrap.iv.length > 64 || wrap.ciphertext.length > 256) return null;
    return { iv: wrap.iv, ciphertext: wrap.ciphertext };
}

function safeVaultEnvelope(value: unknown): VaultEnvelope | null {
    if (!value || typeof value !== 'object') return null;
    const envelope = value as Partial<VaultEnvelope>;
    const password = safeKeyWrap(envelope.password);
    const recovery = safeKeyWrap(envelope.recovery);
    if (
        envelope.version !== 1 ||
        envelope.iterations !== 600000 ||
        typeof envelope.salt !== 'string' ||
        envelope.salt.length > 64 ||
        !password ||
        !recovery
    ) {
        return null;
    }
    return {
        version: 1,
        salt: envelope.salt,
        iterations: 600000,
        password,
        recovery,
    };
}

function safeMetadataEnvelope(value: unknown): MetadataEnvelope | null {
    if (!value || typeof value !== 'object') return null;
    const envelope = value as Partial<MetadataEnvelope>;
    if (
        envelope.version !== 1 ||
        typeof envelope.salt !== 'string' ||
        envelope.salt.length > 64 ||
        typeof envelope.iv !== 'string' ||
        envelope.iv.length > 64 ||
        typeof envelope.ciphertext !== 'string' ||
        envelope.ciphertext.length > 100000
    ) {
        return null;
    }
    return {
        version: 1,
        salt: envelope.salt,
        iv: envelope.iv,
        ciphertext: envelope.ciphertext,
    };
}

export function sanitizeBrowserSnapshot(
    value: BrowserSnapshot,
): BrowserSnapshot {
    const snapshot = {
        ...value,
        storage_name: value.storage_name ?? value.name,
        connection_id: value.connection_id ?? null,
        encryption_mode: value.encryption_mode ?? 'none',
        encryption_header: value.encryption_header ?? null,
        metadata_protected: value.metadata_protected ?? false,
        protected_metadata: value.protected_metadata ?? null,
        vault_envelope: value.vault_envelope ?? null,
    };
    const encrypted = snapshot.encryption_header !== null;
    const mode = snapshot.encryption_mode;
    if (
        !Number.isSafeInteger(snapshot.id) ||
        snapshot.id < 1 ||
        typeof snapshot.name !== 'string' ||
        snapshot.name.length > 255 ||
        typeof snapshot.storage_name !== 'string' ||
        snapshot.storage_name.length > 255 ||
        typeof snapshot.mime_type !== 'string' ||
        snapshot.mime_type.length > 255 ||
        (snapshot.encryption_header !== null &&
            (typeof snapshot.encryption_header !== 'string' ||
                snapshot.encryption_header.length > 256)) ||
        !Number.isSafeInteger(snapshot.size) ||
        snapshot.size < 0 ||
        typeof snapshot.directory_path !== 'string' ||
        snapshot.directory_path.length > 4096 ||
        (snapshot.description !== null &&
            typeof snapshot.description !== 'string') ||
        !Array.isArray(snapshot.tags) ||
        !['none', 'contents', 'contents_and_metadata'].includes(mode ?? '') ||
        (snapshot.connection_id !== null &&
            (!Number.isSafeInteger(snapshot.connection_id) ||
                snapshot.connection_id < 1)) ||
        typeof snapshot.metadata_protected !== 'boolean'
    ) {
        throw new Error('Invalid browser copy details.');
    }

    const vaultEnvelope = snapshot.vault_envelope
        ? safeVaultEnvelope(snapshot.vault_envelope)
        : null;
    const protectedMetadata = snapshot.protected_metadata
        ? safeMetadataEnvelope(snapshot.protected_metadata)
        : null;
    if (
        encrypted &&
        (mode === 'none' ||
            !snapshot.connection_id ||
            !vaultEnvelope ||
            (snapshot.metadata_protected && !protectedMetadata))
    ) {
        throw new Error('Encrypted file details are incomplete.');
    }
    if (!encrypted && (mode !== 'none' || snapshot.metadata_protected)) {
        throw new Error('File encryption details do not match the file.');
    }
    if (
        snapshot.metadata_protected !== (mode === 'contents_and_metadata') ||
        (protectedMetadata !== null && !snapshot.metadata_protected) ||
        (!encrypted && snapshot.vault_envelope)
    ) {
        throw new Error('File encryption details do not match the file.');
    }
    const protectedScope = snapshot.metadata_protected === true;
    const tags = snapshot.tags.map((tag) => {
        if (
            !tag ||
            !Number.isSafeInteger(tag.id) ||
            typeof tag.name !== 'string' ||
            tag.name.length > 255
        ) {
            throw new Error('Invalid browser copy details.');
        }
        return { id: tag.id, name: tag.name };
    });

    return {
        id: snapshot.id,
        name: protectedScope ? 'Protected file' : snapshot.name,
        storage_name: snapshot.storage_name,
        mime_type: protectedScope
            ? 'application/octet-stream'
            : snapshot.mime_type,
        size: snapshot.size,
        directory_path: snapshot.directory_path,
        description: protectedScope ? null : snapshot.description,
        tags: protectedScope ? [] : tags,
        connection_id: snapshot.connection_id,
        encryption_mode: mode as BrowserSnapshot['encryption_mode'],
        encryption_header: snapshot.encryption_header,
        metadata_protected: protectedScope,
        protected_metadata: protectedMetadata,
        vault_envelope: vaultEnvelope,
    };
}

export function browserAccount(): number | null {
    try {
        const id = Number(localStorage.getItem(accountKey));
        return Number.isSafeInteger(id) && id > 0 ? id : null;
    } catch {
        return null;
    }
}
export function browserLogoutEpoch(account: number): string | null {
    try {
        return localStorage.getItem(`roxifile.browser-epoch.${account}`);
    } catch {
        return null;
    }
}
export function setBrowserAccount(account: number): void {
    if (browserAccount() !== account) {
        for (const controller of active) controller.abort();
        localStorage.setItem(accountKey, String(account));
        window.dispatchEvent(new Event(changed));
    }
}
export function retainsBrowserCopies(account: number): boolean {
    try {
        return (
            localStorage.getItem(`roxifile.browser-retain.${account}`) === 'yes'
        );
    } catch {
        return false;
    }
}
export function retainBrowserCopies(account: number, retain: boolean): void {
    localStorage.setItem(
        `roxifile.browser-retain.${account}`,
        retain ? 'yes' : 'no',
    );
}
function requireAccount(account: number): void {
    if (browserAccount() !== account)
        throw new Error(
            'The active account changed. Reopen Browser downloads.',
        );
}
export type MediaSmartRules = {
    tag?: string | null;
    minimum_rating?: number | null;
    favorite_only?: boolean;
    metadata?: string | null;
    minimum_plays?: number | null;
};

export type MediaPlaylist = {
    id: number;
    name: string;
    file_ids: number[];
    folder_id?: number | null;
    cover_file_id?: number | null;
    smart_rules?: MediaSmartRules | null;
};

export function cacheMediaPlaylists(
    account: number,
    playlists: MediaPlaylist[],
): void {
    if (browserAccount() !== account) return;
    localStorage.setItem(
        `roxifile.media-playlists.${account}`,
        JSON.stringify({
            epoch: browserLogoutEpoch(account),
            playlists,
        }),
    );
}

export function cachedMediaPlaylists(account: number): MediaPlaylist[] {
    if (browserAccount() !== account) return [];
    try {
        const saved = JSON.parse(
            localStorage.getItem(`roxifile.media-playlists.${account}`) ??
                'null',
        );
        if (
            !saved ||
            (!retainsBrowserCopies(account) &&
                saved.epoch !== browserLogoutEpoch(account))
        )
            return [];
        return Array.isArray(saved.playlists) ? saved.playlists : [];
    } catch {
        return [];
    }
}

export function browserStorageAvailable(): boolean {
    return !!(
        globalThis.indexedDB &&
        typeof navigator.storage?.getDirectory === 'function' &&
        navigator.locks
    );
}
function database(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('roxifile-browser-downloads', 1);
        request.onupgradeneeded = () =>
            request.result.createObjectStore('copies', {
                keyPath: ['account', 'id'],
            });
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}
async function catalog<T>(
    mode: IDBTransactionMode,
    action: (store: IDBObjectStore) => IDBRequest<T>,
): Promise<T> {
    const db = await database();
    try {
        return await new Promise<T>((resolve, reject) => {
            const transaction = db.transaction('copies', mode);
            const request = action(transaction.objectStore('copies'));
            transaction.oncomplete = () => resolve(request.result);
            transaction.onabort = transaction.onerror = () =>
                reject(
                    transaction.error ??
                        request.error ??
                        new Error('Browser catalog could not be committed.'),
                );
        });
    } finally {
        db.close();
    }
}
async function directory(account: number): Promise<FileSystemDirectoryHandle> {
    const root = await navigator.storage.getDirectory();
    return (
        await root.getDirectoryHandle('roxifile-browser-copies', {
            create: true,
        })
    ).getDirectoryHandle(String(account), { create: true });
}
function announce(): void {
    window.dispatchEvent(new Event(changed));
}
export function onBrowserCopiesChanged(callback: () => void): () => void {
    window.addEventListener(changed, callback);
    window.addEventListener('storage', callback);
    return () => {
        window.removeEventListener(changed, callback);
        window.removeEventListener('storage', callback);
    };
}
export async function listBrowserCopies(
    account: number,
): Promise<BrowserCopy[]> {
    requireAccount(account);
    const rows = (await catalog('readonly', (store) =>
        store.getAll(),
    )) as BrowserCopy[];
    requireAccount(account);
    const folder = await directory(account);
    const copies = await Promise.all(
        rows
            .filter((row) => row.account === account)
            .map(async (row) => {
                try {
                    const file = await (
                        await folder.getFileHandle(row.path)
                    ).getFile();
                    return { ...row, available: file.size === row.size };
                } catch {
                    return { ...row, available: false };
                }
            }),
    );
    requireAccount(account);
    return copies.sort((a, b) => b.saved_at.localeCompare(a.saved_at));
}
export function fileBrowserCopies(
    copies: BrowserCopy[],
    fileId?: number,
): BrowserCopy[] {
    return fileId === undefined
        ? copies
        : copies.filter((copy) => copy.id === fileId);
}

export function localBrowserCopyIds(
    copies: BrowserCopy[],
): ReadonlySet<number> {
    return new Set(
        copies.filter((copy) => copy.available).map((copy) => copy.id),
    );
}
export async function availableBrowserCopyIds(
    account: number,
): Promise<ReadonlySet<number>> {
    if (!browserStorageAvailable() || browserAccount() !== account)
        return new Set();
    try {
        return localBrowserCopyIds(await listBrowserCopies(account));
    } catch {
        return new Set();
    }
}
export async function readBrowserCopy(
    account: number,
    id: number,
): Promise<File> {
    requireAccount(account);
    const row = (await catalog('readonly', (store) =>
        store.get([account, id]),
    )) as BrowserCopy | undefined;
    if (!row)
        throw new Error(
            'This browser copy is missing. Save it again while online.',
        );
    try {
        const file = await (
            await (await directory(account)).getFileHandle(row.path)
        ).getFile();
        requireAccount(account);
        if (file.size !== row.size)
            throw new Error(
                'Incomplete browser copy. Refresh it while online.',
            );
        return file;
    } catch (error) {
        if (error instanceof DOMException && error.name === 'NotFoundError')
            throw new Error(
                'This browser copy was removed or evicted. Refresh it while online.',
            );
        throw error;
    }
}
export async function reconcileBrowserCopies(account: number): Promise<void> {
    requireAccount(account);
    await navigator.locks.request(`roxifile-browser-${account}`, async () => {
        requireAccount(account);
        const rows = await listBrowserCopies(account);
        const keep = new Set(rows.map((row) => row.path));
        const folder = await directory(account);
        for await (const name of (
            folder as unknown as { keys(): AsyncIterable<string> }
        ).keys()) {
            if (!keep.has(name)) await folder.removeEntry(name).catch(() => {});
        }
    });
}
export async function installOfflineShell(): Promise<void> {
    if (!('serviceWorker' in navigator))
        throw new Error(
            'Offline access is unavailable. Use a regular download.',
        );
    const registration = await navigator.serviceWorker.register(
        '/browser-downloads-sw.js',
    );
    if (!registration.active) {
        const worker = registration.installing ?? registration.waiting;
        if (!worker)
            throw new Error(
                'Offline access is not ready. Retry Browser download.',
            );
        await new Promise<void>((resolve, reject) => {
            const timeout = setTimeout(
                () =>
                    finish(
                        new Error(
                            'Offline setup timed out. Retry while online.',
                        ),
                    ),
                30000,
            );
            function finish(error?: Error): void {
                clearTimeout(timeout);
                worker!.removeEventListener('statechange', check);
                if (error) reject(error);
                else resolve();
            }
            function check(): void {
                if (worker!.state === 'activated') finish();
                else if (worker!.state === 'redundant')
                    finish(
                        new Error(
                            'Offline shell is unavailable. Build the application, then retry.',
                        ),
                    );
            }
            worker.addEventListener('statechange', check);
            check();
        });
    }
    if (!(await (await caches.open('roxifile-offline-v1')).match(offlineShell)))
        throw new Error(
            'Offline shell is unavailable. Build the application, then retry.',
        );
}
export async function saveBrowserCopy(
    account: number,
    snapshot: BrowserSnapshot,
    response: Response,
    signal: AbortSignal,
    progress: (bytes: number) => void,
): Promise<{ persistent: boolean }> {
    snapshot = sanitizeBrowserSnapshot(snapshot);
    if (!browserStorageAvailable())
        throw new Error(
            'This browser does not support offline file storage. Use a regular download.',
        );
    requireAccount(account);
    if (!response.ok || !response.body)
        throw new Error(
            'The prepared download expired or failed. Prepare it again.',
        );
    const controller = new AbortController();
    const abort = () => controller.abort();
    const epochKey = `roxifile.browser-epoch.${account}`;
    const epoch = localStorage.getItem(epochKey);
    const accountChanged = () => {
        if (
            browserAccount() !== account ||
            localStorage.getItem(epochKey) !== epoch
        )
            abort();
    };
    signal.addEventListener('abort', abort, { once: true });
    window.addEventListener('storage', accountChanged);
    active.add(controller);
    if (signal.aborted) abort();
    try {
        const persistent =
            (await navigator.storage.persist?.().catch(() => false)) ?? false;
        await navigator.locks.request(
            `roxifile-browser-${account}`,
            { signal: controller.signal },
            async () => {
                requireAccount(account);
                controller.signal.throwIfAborted();
                const folder = await directory(account);
                const path = crypto.randomUUID();
                let writer: FileSystemWritableFileStream | undefined;
                let committed = false;
                const reader = response.body!.getReader();
                const cancelReader = () => {
                    void reader.cancel().catch(() => {});
                };
                controller.signal.addEventListener('abort', cancelReader, {
                    once: true,
                });
                try {
                    writer = await (
                        await folder.getFileHandle(path, { create: true })
                    ).createWritable();
                    let bytes = 0;
                    while (true) {
                        controller.signal.throwIfAborted();
                        const part = await reader.read();
                        if (part.done) break;
                        bytes += part.value.byteLength;
                        if (bytes > snapshot.size)
                            throw new Error(
                                'Download size changed. Refresh the file information and retry.',
                            );
                        await writer.write(part.value);
                        progress(bytes);
                    }
                    controller.signal.throwIfAborted();
                    if (bytes !== snapshot.size)
                        throw new Error(
                            'Download was interrupted. The previous complete copy is preserved.',
                        );
                    await writer.close();
                    writer = undefined;
                    requireAccount(account);
                    const old = (await catalog('readonly', (store) =>
                        store.get([account, snapshot.id]),
                    )) as BrowserCopy | undefined;
                    controller.signal.throwIfAborted();
                    await catalog('readwrite', (store) =>
                        store.put({
                            ...snapshot,
                            account,
                            path,
                            saved_at: new Date().toISOString(),
                            original_missing: false,
                        } satisfies BrowserCopy),
                    );
                    committed = true;
                    if (old) await folder.removeEntry(old.path).catch(() => {});
                    announce();
                } finally {
                    controller.signal.removeEventListener(
                        'abort',
                        cancelReader,
                    );
                    await reader.cancel().catch(() => {});
                    await writer?.abort().catch(() => {});
                    if (!committed)
                        await folder.removeEntry(path).catch(() => {});
                }
            },
        );
        return { persistent };
    } finally {
        active.delete(controller);
        signal.removeEventListener('abort', abort);
        window.removeEventListener('storage', accountChanged);
    }
}
export async function removeBrowserCopy(
    account: number,
    id: number,
): Promise<void> {
    requireAccount(account);
    await navigator.locks.request(`roxifile-browser-${account}`, async () => {
        requireAccount(account);
        const row = (await catalog('readonly', (store) =>
            store.get([account, id]),
        )) as BrowserCopy | undefined;
        await catalog('readwrite', (store) => store.delete([account, id]));
        if (row)
            await (
                await directory(account)
            )
                .removeEntry(row.path)
                .catch(() => {});
    });
    announce();
}
export async function markBrowserOriginalMissing(
    account: number,
    id: number,
    missing: boolean,
): Promise<void> {
    requireAccount(account);
    await navigator.locks.request(`roxifile-browser-${account}`, async () => {
        requireAccount(account);
        const row = (await catalog('readonly', (store) =>
            store.get([account, id]),
        )) as BrowserCopy | undefined;
        if (row)
            await catalog('readwrite', (store) =>
                store.put({ ...row, original_missing: missing }),
            );
    });
    announce();
}
export async function logoutBrowserCopies(account: number): Promise<void> {
    try {
        localStorage.setItem(
            `roxifile.browser-epoch.${account}`,
            crypto.randomUUID(),
        );
    } catch {
        /* Cleanup must still run when browser settings disable local storage. */
    }
    if (!retainsBrowserCopies(account)) {
        try {
            localStorage.removeItem(`roxifile.media-playlists.${account}`);
        } catch {
            /* Continue removing the saved file bytes. */
        }
    }
    for (const controller of active) controller.abort();
    if (!browserStorageAvailable()) {
        if (globalThis.indexedDB && !retainsBrowserCopies(account)) {
            const rows = (await catalog('readonly', (store) =>
                store.getAll(),
            )) as BrowserCopy[];
            if (rows.some((row) => row.account === account))
                throw new Error(
                    'Browser file storage is unavailable; saved copies could not be cleared.',
                );
        }
        if (!retainsBrowserCopies(account) && browserAccount() === account)
            localStorage.removeItem(accountKey);
        return;
    }
    if (!retainsBrowserCopies(account)) {
        if (browserAccount() === account) localStorage.removeItem(accountKey);
        await navigator.locks.request(
            `roxifile-browser-${account}`,
            async () => {
                const rows = (await catalog('readonly', (store) =>
                    store.getAll(),
                )) as BrowserCopy[];
                for (const row of rows.filter((row) => row.account === account))
                    await catalog('readwrite', (store) =>
                        store.delete([account, row.id]),
                    );
                const root = await navigator.storage.getDirectory();
                const folder = await root.getDirectoryHandle(
                    'roxifile-browser-copies',
                    { create: true },
                );
                await folder
                    .removeEntry(String(account), { recursive: true })
                    .catch((error) => {
                        if (error.name !== 'NotFoundError') throw error;
                    });
            },
        );
        if (browserAccount() === account) localStorage.removeItem(accountKey);
    }
    announce();
}
