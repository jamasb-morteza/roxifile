import { createApp, h } from 'vue';
import OfflineEncryptedCopy from '../../resources/js/components/OfflineEncryptedCopy.vue';
import {
    createVault,
    encryptFile,
    encryptMetadata,
} from '../../resources/js/lib/contentsEncryption.ts';
import {
    installOfflineShell,
    listBrowserCopies,
    saveBrowserCopy,
    setBrowserAccount,
} from '../../resources/js/lib/browserDownloads.ts';

window.ready = false;
window.bootError = '';
void (async () => {
    try {
        const context = { userId: 7, connectionId: 19 };
        const vault = await createVault(
            'separate offline vault password',
            context,
        );
        const metadata = await encryptMetadata(
            {
                name: 'secret-photo.png',
                mime: 'image/png',
                tags: ['private'],
                description: 'offline description',
            },
            vault.key,
            context,
            'opaque-photo',
        );
        const bytes = Uint8Array.from(
            atob(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC',
            ),
            (character) => character.charCodeAt(0),
        );
        const encrypted = await encryptFile(
            new Blob([bytes], { type: 'image/png' }),
            vault.key,
            context,
        );
        const contentsEncrypted = await encryptFile(
            new Blob([bytes], { type: 'image/png' }),
            vault.key,
            context,
        );
        const waveBytes = new Uint8Array(44 + 8000);
        const waveView = new DataView(waveBytes.buffer);
        waveBytes.set(new TextEncoder().encode('RIFF'), 0);
        waveView.setUint32(4, waveBytes.byteLength - 8, true);
        waveBytes.set(new TextEncoder().encode('WAVEfmt '), 8);
        waveView.setUint32(16, 16, true);
        waveView.setUint16(20, 1, true);
        waveView.setUint16(22, 1, true);
        waveView.setUint32(24, 8000, true);
        waveView.setUint32(28, 8000, true);
        waveView.setUint16(32, 1, true);
        waveView.setUint16(34, 8, true);
        waveBytes.set(new TextEncoder().encode('data'), 36);
        waveView.setUint32(40, waveBytes.byteLength - 44, true);
        waveBytes.fill(128, 44);
        const encryptedWave = await encryptFile(
            new Blob([waveBytes], { type: 'audio/wav' }),
            vault.key,
            context,
        );
        const canvas = document.createElement('canvas');
        canvas.width = 16;
        canvas.height = 16;
        const stream = canvas.captureStream(10);
        const chunks = [];
        const recorder = new MediaRecorder(stream, {
            mimeType: 'video/webm;codecs=vp8',
        });
        const finished = new Promise((resolve) => {
            recorder.onstop = resolve;
        });
        recorder.ondataavailable = (event) => chunks.push(event.data);
        recorder.start();
        const context2d = canvas.getContext('2d');
        for (let index = 0; index < 12; index++) {
            context2d.fillStyle = index % 2 ? 'red' : 'blue';
            context2d.fillRect(0, 0, 16, 16);
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
        recorder.stop();
        await finished;
        stream.getTracks().forEach((track) => track.stop());
        const encryptedVideo = await encryptFile(
            new Blob(chunks, { type: 'video/webm' }),
            vault.key,
            context,
        );
        const header = btoa(
            String.fromCharCode(
                ...new Uint8Array(
                    await encrypted.file.slice(0, 64).arrayBuffer(),
                ),
            ),
        );
        const snapshot = {
            id: 1,
            name: 'Protected file',
            storage_name: 'opaque-photo',
            mime_type: 'application/octet-stream',
            size: encrypted.file.size,
            directory_path: 'Readable folder',
            description: null,
            tags: [],
            connection_id: context.connectionId,
            encryption_mode: 'contents_and_metadata',
            encryption_header: header,
            metadata_protected: true,
            protected_metadata: metadata,
            vault_envelope: vault.envelope,
        };
        setBrowserAccount(context.userId);
        await saveBrowserCopy(
            context.userId,
            snapshot,
            new Response(await encrypted.file.arrayBuffer()),
            new AbortController().signal,
            () => {},
        );
        const contentsHeader = btoa(
            String.fromCharCode(
                ...new Uint8Array(
                    await contentsEncrypted.file.slice(0, 64).arrayBuffer(),
                ),
            ),
        );
        await saveBrowserCopy(
            context.userId,
            {
                ...snapshot,
                id: 2,
                name: 'visible-photo.png',
                storage_name: 'visible-photo.png',
                mime_type: 'image/png',
                encryption_mode: 'contents',
                encryption_header: contentsHeader,
                metadata_protected: false,
                protected_metadata: null,
            },
            new Response(await contentsEncrypted.file.arrayBuffer()),
            new AbortController().signal,
            () => {},
        );
        const waveHeader = btoa(
            String.fromCharCode(
                ...new Uint8Array(
                    await encryptedWave.file.slice(0, 64).arrayBuffer(),
                ),
            ),
        );
        await saveBrowserCopy(
            context.userId,
            {
                ...snapshot,
                id: 3,
                name: 'offline-tone.wav',
                storage_name: 'offline-tone.wav',
                mime_type: 'audio/wav',
                size: encryptedWave.file.size,
                encryption_mode: 'contents',
                encryption_header: waveHeader,
                metadata_protected: false,
                protected_metadata: null,
            },
            new Response(await encryptedWave.file.arrayBuffer()),
            new AbortController().signal,
            () => {},
        );
        const videoHeader = btoa(
            String.fromCharCode(
                ...new Uint8Array(
                    await encryptedVideo.file.slice(0, 64).arrayBuffer(),
                ),
            ),
        );
        await saveBrowserCopy(
            context.userId,
            {
                ...snapshot,
                id: 4,
                name: 'offline-clip.webm',
                storage_name: 'offline-clip.webm',
                mime_type: 'video/webm',
                size: encryptedVideo.file.size,
                encryption_mode: 'contents',
                encryption_header: videoHeader,
                metadata_protected: false,
                protected_metadata: null,
            },
            new Response(await encryptedVideo.file.arrayBuffer()),
            new AbortController().signal,
            () => {},
        );
        await encrypted.dispose();
        await contentsEncrypted.dispose();
        await encryptedWave.dispose();
        await encryptedVideo.dispose();
        const copies = await listBrowserCopies(context.userId);
        window.copy = copies.find((copy) => copy.id === 1);
        window.contentsCopy = copies.find((copy) => copy.id === 2);
        window.workflow = { installOfflineShell, setBrowserAccount };
        createApp({
            render: () =>
                h('div', [
                    h('section', { 'data-test': 'browser-copy' }, [
                        h(OfflineEncryptedCopy, {
                            account: context.userId,
                            copy: window.copy,
                        }),
                    ]),
                    h('section', { 'data-test': 'browser-copy' }, [
                        h(OfflineEncryptedCopy, {
                            account: context.userId,
                            copy: window.contentsCopy,
                        }),
                    ]),
                    h('section', { 'data-test': 'browser-copy' }, [
                        h(OfflineEncryptedCopy, {
                            account: context.userId,
                            copy: copies.find((copy) => copy.id === 3),
                        }),
                    ]),
                    h('section', { 'data-test': 'browser-copy' }, [
                        h(OfflineEncryptedCopy, {
                            account: context.userId,
                            copy: copies.find((copy) => copy.id === 4),
                        }),
                    ]),
                ]),
        }).mount('#app');
        window.ready = true;
    } catch (error) {
        window.bootError =
            error instanceof Error ? error.message : String(error);
        window.ready = true;
    }
})();
