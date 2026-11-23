import {
    decryptFile,
    decryptFileStream,
    decryptMetadata,
    unlockVault,
} from './contentsEncryption';
import type {
    MetadataEnvelope,
    ProtectedMetadata,
    StagedFile,
    VaultContext,
} from './contentsEncryption';
import type { BrowserCopy } from './browserDownloads';

export type UnlockedBrowserCopy = {
    key: CryptoKey;
    metadata: ProtectedMetadata | null;
};

function contextFor(account: number, copy: BrowserCopy): VaultContext {
    if (
        !Number.isSafeInteger(account) ||
        account < 1 ||
        !Number.isSafeInteger(copy.connection_id) ||
        !copy.connection_id
    ) {
        throw new Error('The saved connection is unavailable.');
    }
    return { userId: account, connectionId: copy.connection_id };
}

export async function unlockBrowserCopy(
    copy: BrowserCopy,
    account: number,
    credential: string,
    recovery = false,
): Promise<UnlockedBrowserCopy> {
    if (
        !copy.encryption_header ||
        copy.encryption_mode === 'none' ||
        !copy.vault_envelope
    ) {
        throw new Error('The saved vault information is unavailable.');
    }
    const context = contextFor(account, copy);
    const key = await unlockVault(
        copy.vault_envelope,
        credential,
        context,
        recovery,
    );
    let metadata: ProtectedMetadata | null = null;
    if (copy.metadata_protected) {
        if (!copy.protected_metadata) {
            throw new Error('Protected file details are unavailable.');
        }
        metadata = await decryptMetadata(
            copy.protected_metadata as MetadataEnvelope,
            key,
            context,
            copy.storage_name,
        );
    }
    return { key, metadata };
}

export function decryptBrowserCopy(
    copy: BrowserCopy,
    account: number,
    key: CryptoKey,
    ciphertext: Blob,
    signal?: AbortSignal,
    destination?: FileSystemWritableFileStream,
): Promise<StagedFile> {
    if (!copy.encryption_header || copy.encryption_mode === 'none') {
        throw new Error('The saved file is not an encrypted browser copy.');
    }
    return decryptFile(
        ciphertext,
        key,
        contextFor(account, copy),
        signal,
        copy.encryption_header,
        destination,
    );
}

export function decryptBrowserCopyStream(
    copy: BrowserCopy,
    account: number,
    key: CryptoKey,
    ciphertext: Blob,
    signal?: AbortSignal,
): Promise<ReadableStream<Uint8Array<ArrayBuffer>>> {
    if (!copy.encryption_header || copy.encryption_mode === 'none') {
        throw new Error('The saved file is not an encrypted browser copy.');
    }
    return decryptFileStream(
        ciphertext,
        key,
        contextFor(account, copy),
        signal,
        copy.encryption_header,
    );
}
