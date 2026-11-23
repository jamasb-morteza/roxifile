import * as files from '@/routes/files';
import * as operations from '@/routes/file-operations';
import type { MetadataEnvelope, VaultEnvelope } from './contentsEncryption';
import {
    browserStorageAvailable,
    installOfflineShell,
    saveBrowserCopy,
    type BrowserSnapshot,
} from './browserDownloads';
export async function browserOriginal(
    id: number,
    signal?: AbortSignal,
): Promise<BrowserSnapshot | null> {
    const response = await fetch(files.index.url({ query: { file: id } }), {
        headers: { Accept: 'application/json' },
        signal,
    });
    if (response.status === 404) return null;
    if (!response.ok)
        throw new Error(
            'Sign in online to check or refresh the original file.',
        );
    const body = (await response.json()) as {
        selected_file: BrowserSnapshot & {
            encryption_header?: string | null;
            encryption_mode?: BrowserSnapshot['encryption_mode'];
            connection_id?: number | null;
            metadata_protected?: boolean;
            protected_metadata?: MetadataEnvelope | null;
            vault_envelope?: VaultEnvelope | null;
        };
    };
    const file = body.selected_file;
    if (!file)
        throw new Error(
            'File details are unavailable. Sign in online and try again.',
        );
    const encryptionHeader = file.encryption_header ?? null;
    const metadataProtected = file.metadata_protected ?? false;
    const encryptionMode = file.encryption_mode ?? 'none';
    const vaultEnvelope = file.vault_envelope ?? null;
    if (
        encryptionHeader !== null &&
        (!file.connection_id || encryptionMode === 'none' || !vaultEnvelope)
    ) {
        throw new Error(
            'Encrypted file details are incomplete. Unlock the connection online and retry.',
        );
    }
    return {
        id: file.id,
        name: file.name,
        storage_name: file.storage_name ?? file.name,
        mime_type: file.mime_type,
        size: file.size,
        directory_path: file.directory_path,
        description: file.description,
        tags: file.tags,
        connection_id: file.connection_id ?? null,
        encryption_mode: encryptionMode,
        encryption_header: encryptionHeader,
        metadata_protected: metadataProtected,
        protected_metadata: file.protected_metadata ?? null,
        vault_envelope: vaultEnvelope,
    };
}
export async function downloadToBrowser(
    account: number,
    id: number,
    signal: AbortSignal,
    progress: (bytes: number, total: number) => void,
): Promise<{ persistent: boolean }> {
    if (!browserStorageAvailable())
        throw new Error(
            'This browser does not support offline file storage. Use a regular download.',
        );
    await installOfflineShell();
    const file = await browserOriginal(id, signal);
    if (!file)
        throw new Error(
            'The original file is missing. Any saved browser copy is preserved.',
        );
    const streamed = await fetch(files.streamDownload({ file: id }).url, {
        signal,
    });
    let response = streamed;

    if (streamed.status === 501 || streamed.body === null) {
        await streamed.body?.cancel();
        response = await fetch(await preparedDownloadUrl(id, signal), {
            signal,
        });
    } else if (streamed.status === 413) {
        const failure = (await streamed.json().catch(() => null)) as {
            message?: string;
        } | null;
        throw new Error(
            failure?.message ??
                'This file exceeds the Telegram streaming size limit.',
        );
    }

    if (!response.ok)
        throw new Error(
            response.status === 502
                ? 'The Telegram worker could not stream this file. Retry the download.'
                : 'The download could not be streamed. Retry the download.',
        );

    return saveBrowserCopy(account, file, response, signal, (bytes) =>
        progress(bytes, file.size),
    );
}

export async function preparedDownloadUrl(
    id: number,
    signal: AbortSignal,
    inline = false,
): Promise<string> {
    const token = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length) ?? '',
    );
    const prepared = await fetch(files.prepareDownload({ file: id }).url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token },
        signal,
    });
    if (!prepared.ok)
        throw new Error(
            'The file could not be prepared. Sign in or try again.',
        );
    let operation = (await prepared.json()) as {
        operation_id: number;
        status: string;
    };
    while (operation.status !== 'completed') {
        if (['failed', 'cancelled', 'needs_review'].includes(operation.status))
            throw new Error(
                'Preparation failed. Try again; the saved snapshot is preserved.',
            );
        await new Promise<void>((resolve, reject) => {
            const abort = () => {
                clearTimeout(timer);
                reject(signal.reason);
            };
            const timer = setTimeout(() => {
                signal.removeEventListener('abort', abort);
                resolve();
            }, 1000);
            signal.addEventListener('abort', abort, { once: true });
            if (signal.aborted) abort();
        });
        const response = await fetch(
            operations.index({ query: { ids: [operation.operation_id] } }).url,
            { headers: { Accept: 'application/json' }, signal },
        );
        if (!response.ok)
            throw new Error('Could not check preparation. Try again.');
        const body = (await response.json()) as {
            operations: { operation_id: number; status: string }[];
        };
        const current = body.operations.find(
            (row) => row.operation_id === operation.operation_id,
        );
        if (!current) throw new Error('Preparation is unavailable. Try again.');
        operation = current;
    }
    return files.download(
        { file: id },
        {
            query: {
                operation_id: operation.operation_id,
                inline: inline ? 1 : 0,
            },
        },
    ).url;
}
