import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { once } from 'node:events';

void test(
    'protected file UI encrypts uploads and edits, filters locally, downloads and clears on lock',
    { skip: !process.env.CHROME_BIN, timeout: 120000 },
    async () => {
        const { createServer } = await import('vite');
        const { default: vue } = await import('@vitejs/plugin-vue');
        const root = path.resolve(import.meta.dirname, '../..');
        const directory = await mkdtemp(
            path.join(tmpdir(), 'roxifile-metadata-browser-'),
        );
        const requests = [];
        let file = null;
        let mediaFiles = [];
        let mediaCiphertexts = new Map();
        let ciphertext;
        let envelope;
        let userId = 7;
        let conflicts = 0;
        const props = () => ({
            auth: {
                user: {
                    id: userId,
                    name: 'Owner',
                    email: 'owner@example.test',
                },
            },
            errors: {},
            max_upload_bytes: 7340032,
            directory: null,
            directories: [],
            breadcrumbs: [],
            operations: [],
            workerStatus: null,
            connections: [
                {
                    id: 19,
                    name: 'Protected',
                    method: 'invite',
                    encryption_mode: 'contents_and_metadata',
                    vault_envelope: envelope,
                },
            ],
            vault_connections: [],
            filter_connections: [
                { id: 19, name: 'Protected', chat_title: 'Storage' },
            ],
            files: {
                data: [file, ...mediaFiles].filter(Boolean),
                current_page: 1,
                last_page: 1,
                total: [file, ...mediaFiles].filter(Boolean).length,
            },
            filters: {
                q: null,
                file_type: null,
                mime_type: null,
                tag_ids: [],
                telegram_connection_id: 19,
                created_after: null,
                created_before: null,
                scope: 'folder',
                sort: 'updated_at',
                direction: 'desc',
                trash: false,
            },
        });
        const page = () => ({
            component: 'files/Index',
            props: props(),
            url: '/files?telegram_connection_id=19',
            version: 'test',
            clearHistory: false,
            encryptHistory: false,
        });
        const server = await createServer({
            configFile: false,
            root,
            plugins: [
                vue(),
                {
                    name: 'fake-broadcast-transport',
                    enforce: 'pre',
                    resolveId(id) {
                        if (id.endsWith('/composables/useTelegramEvents'))
                            return '\0fake-broadcast';
                    },
                    load(id) {
                        if (id === '\0fake-broadcast')
                            return 'export function useTelegramEvents() {}';
                    },
                },
            ],
            optimizeDeps: {
                noDiscovery: true,
                include: [
                    'vue',
                    '@inertiajs/vue3',
                    '@vueuse/core',
                    '@lucide/vue',
                    'vue-sonner',
                    'clsx',
                    'class-variance-authority',
                    'reka-ui',
                    'tailwind-merge',
                ],
            },
            resolve: { alias: { '@': path.join(root, 'resources/js') } },
            server: { host: '127.0.0.1', port: 0, watch: null },
        });
        server.middlewares.stack.unshift({
            route: '',
            handle: async (req, res, next) => {
                if (req.url === '/__protected_media_fixture') {
                    const chunks = [];
                    for await (const chunk of req) chunks.push(chunk);
                    const fixture = JSON.parse(
                        Buffer.concat(chunks).toString(),
                    );
                    mediaFiles = fixture.files.map(({ file }) => file);
                    mediaCiphertexts = new Map(
                        fixture.files.map(({ file, ciphertext }) => [
                            file.id,
                            Buffer.from(ciphertext, 'base64'),
                        ]),
                    );
                    res.end('ok');
                    return;
                }
                if (req.url === '/__metadata_test') {
                    res.setHeader('Content-Type', 'text/html');
                    res.end(
                        await server.transformIndexHtml(
                            '/__metadata_test',
                            `<html><body><div id="app"></div><script type="module">
                import { createApp, h } from 'vue';
                import { createInertiaApp, router } from '@inertiajs/vue3';
                window.testRouter = router;
                import Index from '/resources/js/pages/files/Index.vue';
                import { SidebarProvider } from '/resources/js/components/ui/sidebar/index.ts';
                import * as cryptoLib from '/resources/js/lib/contentsEncryption.ts';
                import { setBrowserAccount } from '/resources/js/lib/browserDownloads.ts';
                localStorage.setItem('roxifile.files.view', 'list');
                setBrowserAccount(7);
                const context = { userId: 7, connectionId: 19 };
                const vault = ${JSON.stringify(envelope)} ? { envelope: ${JSON.stringify(envelope)}, key: await cryptoLib.unlockVault(${JSON.stringify(envelope)}, 'separate vault password', context) } : await cryptoLib.createVault('separate vault password', context);
                window.envelope = vault.envelope;
                const page = ${JSON.stringify(page())}; page.props.connections[0].vault_envelope = vault.envelope;
                if (!${JSON.stringify(envelope)}) {
                const mediaFixtures = [];
                async function addProtectedMedia(id, name, mime, blob) {
                    const identity = 'storage-' + id;
                    const encryptedMedia = await cryptoLib.encryptFile(blob, vault.key, context);
                    const mediaHeader = new Uint8Array(await encryptedMedia.file.slice(0, 64).arrayBuffer());
                    const protectedMetadata = await cryptoLib.encryptMetadata({ name, mime, description: '', tags: [] }, vault.key, context, identity);
                    const protectedFile = { id, connection_id: 19, storage_name: identity, name: 'Protected file', metadata_protected: true, protected_metadata: protectedMetadata, encryption_header: btoa(String.fromCharCode(...mediaHeader)), mime_type: 'application/octet-stream', file_type: 'other', size: encryptedMedia.file.size, status: 'uploaded', description: null, tags: [], directory_id: null, directory_path: '', flagged: false, created_at: '2026-10-29T00:00:00Z', updated_at: '2026-10-29T00:00:00Z', deleted_at: null, telegram: { account: 'Roxi', connection: 'Protected', method: 'invite', chat_id: 19, message_id: id } };
                    mediaFixtures.push({ file: protectedFile, ciphertext: btoa(String.fromCharCode(...new Uint8Array(await encryptedMedia.file.arrayBuffer()))) });
                    page.props.files.data.push(protectedFile);
                    page.props.files.total++;
                }
                const png = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP6sAAAAASUVORK5CYII='), character => character.charCodeAt(0));
                await addProtectedMedia(2, 'private-picture.png', 'image/png', new Blob([png], { type: 'image/png' }));
                const canvas = document.createElement('canvas'); canvas.width = 32; canvas.height = 32; const canvasContext = canvas.getContext('2d');
                const videoStream = canvas.captureStream(10); const videoRecorder = new MediaRecorder(videoStream, { mimeType: 'video/webm;codecs=vp8' }); const videoChunks = []; videoRecorder.ondataavailable = event => { if (event.data.size) videoChunks.push(event.data); }; const videoStopped = new Promise(resolve => videoRecorder.addEventListener('stop', resolve, { once: true })); videoRecorder.start(); for (let frame = 0; frame < 10; frame++) { canvasContext.fillStyle = frame % 2 ? 'blue' : 'red'; canvasContext.fillRect(0, 0, 32, 32); await new Promise(resolve => setTimeout(resolve, 100)); } videoRecorder.stop(); await videoStopped; videoStream.getTracks().forEach(track => track.stop());
                await addProtectedMedia(3, 'private-video.webm', 'video/webm;codecs=vp8', new Blob(videoChunks, { type: 'video/webm' }));
                const audioContext = new AudioContext(); const destination = audioContext.createMediaStreamDestination(); const oscillator = audioContext.createOscillator(); oscillator.connect(destination); oscillator.start(); const audioRecorder = new MediaRecorder(destination.stream, { mimeType: 'audio/webm;codecs=opus' }); const audioChunks = []; audioRecorder.ondataavailable = event => { if (event.data.size) audioChunks.push(event.data); }; const audioStopped = new Promise(resolve => audioRecorder.addEventListener('stop', resolve, { once: true })); audioRecorder.start(); await new Promise(resolve => setTimeout(resolve, 1000)); audioRecorder.stop(); await audioStopped; oscillator.stop(); destination.stream.getTracks().forEach(track => track.stop()); await audioContext.close();
                await addProtectedMedia(4, 'private-audio.webm', 'audio/webm;codecs=opus', new Blob(audioChunks, { type: 'audio/webm' }));
                await fetch('/__protected_media_fixture', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ files: mediaFixtures }) });
                }
                await createInertiaApp({ page, resolve: () => Index, setup({ el, App, props, plugin }) { createApp({ render: () => h(SidebarProvider, () => h(App, props)) }).use(plugin).mount(el); } });
                window.ready = true;
            </script></body></html>`,
                        ),
                    );
                    return;
                }
                if (req.url === '/__logout_test') {
                    res.setHeader('Content-Type', 'text/html');
                    res.end(
                        await server.transformIndexHtml(
                            req.url,
                            `<html><body><script type="module">
                    import { setBrowserAccount, retainBrowserCopies, logoutBrowserCopies } from '/resources/js/lib/browserDownloads.ts';
                    setBrowserAccount(7);
                    retainBrowserCopies(7, true);
                    window.logout = () => logoutBrowserCopies(7);
                    window.ready = true;
                    </script></body></html>`,
                        ),
                    );
                    return;
                }
                if (
                    !req.url.startsWith('/files') &&
                    !req.url.startsWith('/tags')
                )
                    return next();
                const chunks = [];
                for await (const chunk of req) chunks.push(chunk);
                const body = Buffer.concat(chunks);
                requests.push({
                    url: req.url,
                    method: req.method,
                    body: body.toString('latin1'),
                });
                res.setHeader('Content-Type', 'application/json');
                if (req.url.startsWith('/files?') || req.url === '/files') {
                    if (req.headers['x-inertia']) {
                        res.setHeader('X-Inertia', 'true');
                        res.end(JSON.stringify(page()));
                    } else res.end(JSON.stringify({ files: props().files }));
                } else if (/^\/files\/[234]\/prepare-download$/.test(req.url)) {
                    res.end(
                        JSON.stringify({
                            operation_id: 3,
                            status: 'completed',
                        }),
                    );
                } else if (/^\/files\/[234]\/download/.test(req.url)) {
                    res.setHeader('Content-Type', 'application/octet-stream');
                    const fileId = Number(req.url.match(/^\/files\/(\d+)/)[1]);
                    res.end(mediaCiphertexts.get(fileId));
                } else if (req.url === '/tags')
                    res.end(JSON.stringify({ tags: [] }));
                else if (req.url === '/files/upload') {
                    const boundary =
                        req.headers['content-type'].split('boundary=')[1];
                    const fields = {};
                    for (const part of body
                        .toString('latin1')
                        .split('--' + boundary)) {
                        const split = part.indexOf('\r\n\r\n');
                        if (split < 0) continue;
                        const header = part.slice(0, split);
                        const name = header.match(/name="([^"]+)"/)?.[1];
                        const value = part.slice(split + 4, -2);
                        if (name === 'file') {
                            ciphertext = Buffer.from(value, 'latin1');
                            fields.filename =
                                header.match(/filename="([^"]+)"/)[1];
                        } else if (name) fields[name] = value;
                    }
                    file = {
                        id: 1,
                        connection_id: 19,
                        storage_name: fields.filename,
                        name: fields.filename,
                        metadata_protected: true,
                        protected_metadata: {
                            version: Number(
                                fields['protected_metadata[version]'],
                            ),
                            salt: fields['protected_metadata[salt]'],
                            iv: fields['protected_metadata[iv]'],
                            ciphertext:
                                fields['protected_metadata[ciphertext]'],
                        },
                        encryption_header: ciphertext
                            .subarray(0, 64)
                            .toString('base64'),
                        mime_type: 'application/octet-stream',
                        file_type: 'other',
                        size: ciphertext.length,
                        status: 'uploaded',
                        description: null,
                        tags: [],
                        directory_id: null,
                        directory_path: '',
                        flagged: false,
                        created_at: '2026-10-29T00:00:00Z',
                        updated_at: '2026-10-29T00:00:00Z',
                        deleted_at: null,
                        telegram: {
                            account: 'Roxi',
                            connection: 'Protected',
                            method: 'invite',
                            chat_id: 19,
                            message_id: 1,
                        },
                    };
                    res.statusCode = 202;
                    res.end(
                        JSON.stringify({
                            file_id: 1,
                            operation_id: 1,
                            status: 'completed',
                        }),
                    );
                } else if (req.url === '/files/1/protected-metadata') {
                    const data = JSON.parse(body);
                    if (
                        JSON.stringify(data.previous_metadata) !==
                        JSON.stringify(file.protected_metadata)
                    ) {
                        conflicts++;
                        res.statusCode = 409;
                        res.end(
                            JSON.stringify({ message: 'Metadata changed.' }),
                        );
                        return;
                    }
                    file.protected_metadata = data.protected_metadata;
                    res.end(JSON.stringify({ file_id: 1 }));
                } else if (req.url === '/files/1/prepare-download') {
                    res.statusCode = 202;
                    res.end(
                        JSON.stringify({
                            operation_id: 2,
                            status: 'completed',
                        }),
                    );
                } else if (req.url.startsWith('/files/1/download')) {
                    res.setHeader('Content-Type', 'application/octet-stream');
                    res.end(ciphertext);
                } else {
                    res.statusCode = 404;
                    res.end('{}');
                }
            },
        });

        let chrome;
        let socket;
        try {
            await server.listen();
            const origin = `http://127.0.0.1:${server.httpServer.address().port}`;
            chrome = spawn(
                process.env.CHROME_BIN,
                [
                    '--no-sandbox',
                    '--headless',
                    '--disable-gpu',
                    '--remote-debugging-port=0',
                    `--user-data-dir=${path.join(directory, 'profile')}`,
                    'about:blank',
                ],
                { stdio: ['ignore', 'ignore', 'pipe'] },
            );
            const endpoint = await new Promise((resolve, reject) => {
                let output = '';
                chrome.once('error', reject);
                chrome.once('exit', () =>
                    reject(new Error(`Browser exited: ${output}`)),
                );
                chrome.stderr.on('data', (chunk) => {
                    output += chunk;
                    const match = output.match(
                        /DevTools listening on (ws:\/\/[^\s]+)/,
                    );
                    if (match) resolve(match[1]);
                });
            });
            socket = new WebSocket(endpoint);
            await once(socket, 'open');
            let sequence = 0;
            const pending = new Map();
            socket.addEventListener('message', (event) => {
                const response = JSON.parse(event.data);
                const callback = pending.get(response.id);
                if (callback) {
                    pending.delete(response.id);
                    if (response.error)
                        callback.reject(new Error(response.error.message));
                    else callback.resolve(response.result);
                }
            });
            function send(method, params = {}, sessionId) {
                const id = ++sequence;
                return new Promise((resolve, reject) => {
                    pending.set(id, { resolve, reject });
                    socket.send(
                        JSON.stringify({ id, method, params, sessionId }),
                    );
                });
            }
            const { targetId } = await send('Target.createTarget', {
                url: 'about:blank',
            });
            const { sessionId } = await send('Target.attachToTarget', {
                targetId,
                flatten: true,
            });
            async function evaluate(expression) {
                return evaluateIn(expression, sessionId);
            }
            async function evaluateIn(expression, targetSessionId) {
                const response = await send(
                    'Runtime.evaluate',
                    {
                        expression,
                        awaitPromise: true,
                        returnByValue: true,
                        userGesture: true,
                    },
                    targetSessionId,
                );
                assert.equal(
                    response.exceptionDetails,
                    undefined,
                    JSON.stringify(response.exceptionDetails),
                );
                return response.result.value;
            }
            async function waitFor(expression) {
                for (let attempt = 0; attempt < 200; attempt++) {
                    if (await evaluate(expression)) return;
                    await new Promise((resolve) => setTimeout(resolve, 30));
                }
                assert.fail(
                    `Timed out: ${expression}; ${await evaluate('document.body.innerText')}`,
                );
            }
            async function waitForIn(expression, targetSessionId) {
                for (let attempt = 0; attempt < 200; attempt++) {
                    if (await evaluateIn(expression, targetSessionId)) return;
                    await new Promise((resolve) => setTimeout(resolve, 30));
                }
                assert.fail(
                    `Timed out: ${expression}; ${await evaluateIn('document.body.innerText', targetSessionId)}`,
                );
            }
            async function open() {
                await send(
                    'Page.navigate',
                    { url: `${origin}/__metadata_test` },
                    sessionId,
                );
                await waitFor('window.ready === true');
            }
            async function fill(id, value) {
                await evaluate(
                    `{ const input = document.getElementById(${JSON.stringify(id)}); input.value = ${JSON.stringify(value)}; input.dispatchEvent(new Event('input', { bubbles: true })); }`,
                );
            }
            async function click(label) {
                await evaluate(
                    `Array.from(document.querySelectorAll('button')).find(b => b.textContent.trim() === ${JSON.stringify(label)}).click()`,
                );
            }
            await send('Browser.setDownloadBehavior', {
                behavior: 'allow',
                downloadPath: directory,
            });
            await open();
            envelope = await evaluate('window.envelope');
            await fill('vault-unlock-19', 'separate vault password');
            await click('Unlock on this device');
            await waitFor(
                "document.body.innerText.includes('Unlocked on this page')",
            );
            await waitFor(
                "Array.from(document.querySelectorAll('[data-test=file-row]')).some(row => row.innerText.includes('private-picture.png'))",
            );
            await evaluate(
                "Array.from(document.querySelectorAll('[data-test=file-row]')).find(row => row.innerText.includes('private-picture.png')).querySelector('[data-test=entry-menu-button]').click()",
            );
            await waitFor(
                "Array.from(document.querySelectorAll('[role=menuitem]')).some(item => item.textContent.includes('Details & preview'))",
            );
            await evaluate(
                "Array.from(document.querySelectorAll('[role=menuitem]')).find(item => item.textContent.includes('Details & preview')).click()",
            );
            await waitFor(
                'document.querySelector("[data-test=media-viewer] img")?.naturalWidth === 1',
            );
            assert.equal(
                mediaCiphertexts
                    .get(2)
                    .includes(Buffer.from('private-picture.png')),
                false,
            );
            await click('Close');
            async function previewProtectedMedia(name, fileId, selector) {
                await evaluate(
                    `Array.from(document.querySelectorAll('[data-test=file-row]')).find(row => row.innerText.includes(${JSON.stringify(name)})).querySelector('[data-test=entry-menu-button]').click()`,
                );
                await waitFor(
                    "Array.from(document.querySelectorAll('[role=menuitem]')).some(item => item.textContent.includes('Details & preview'))",
                );
                await evaluate(
                    "Array.from(document.querySelectorAll('[role=menuitem]')).find(item => item.textContent.includes('Details & preview')).click()",
                );
                await waitFor(
                    `document.querySelector('[data-test=media-viewer] ${selector}')?.readyState >= 2`,
                );
                assert.equal(
                    mediaCiphertexts.get(fileId).includes(Buffer.from(name)),
                    false,
                );
                await click('Close');
            }
            await previewProtectedMedia('private-video.webm', 3, 'video');
            await previewProtectedMedia('private-audio.webm', 4, 'audio');
            await evaluate(
                `{ const input = document.querySelector('input[type=file][multiple]'); const transfer = new DataTransfer(); transfer.items.add(new File(['private file content'], 'private-secret.txt', { type: 'text/plain' })); input.files = transfer.files; input.dispatchEvent(new Event('change', { bubbles: true })); }`,
            );
            await waitFor(
                "Array.from(document.querySelectorAll('button')).some(b => b.textContent.trim() === 'private-secret.txt')",
            );
            assert.equal(ciphertext.subarray(0, 8).toString(), 'ROXIF001');
            assert.ok(
                !ciphertext.includes(Buffer.from('private file content')),
            );
            await click('private-secret.txt');
            await waitFor("document.getElementById('description') !== null");
            await fill('description', 'private description <b>formatted</b>');
            await evaluate(
                "document.getElementById('description').dispatchEvent(new Event('blur'))",
            );
            await waitFor(
                'document.querySelector(\'input[placeholder="Add a tag…"]\') !== null',
            );
            for (
                let attempt = 0;
                attempt < 200 &&
                requests.filter(
                    (request) => request.url === '/files/1/protected-metadata',
                ).length < 1;
                attempt++
            ) {
                await new Promise((resolve) => setTimeout(resolve, 30));
            }
            assert.equal(
                requests.filter(
                    (request) => request.url === '/files/1/protected-metadata',
                ).length,
                1,
            );
            await evaluate(
                `{ const input = document.querySelector('input[placeholder="Add a tag…"]'); input.value = 'private-tag'; input.dispatchEvent(new Event('input', { bubbles: true })); }`,
            );
            await click('Add');
            await waitFor("document.body.innerText.includes('private-tag')");
            await evaluate(`{
                const encrypt = crypto.subtle.encrypt.bind(crypto.subtle);
                crypto.subtle.encrypt = async (...args) => {
                    if (window.holdMetadataEncryption && new TextDecoder().decode(args[0].additionalData ?? new Uint8Array()).includes(':metadata:')) {
                        window.holdMetadataEncryption = false;
                        await new Promise(resolve => { window.releaseMetadataEncryption = resolve; });
                    }
                    return encrypt(...args);
                };
                window.holdMetadataEncryption = true;
            }`);
            await fill('description', 'private concurrent description');
            await evaluate(
                "document.getElementById('description').dispatchEvent(new Event('blur'))",
            );
            await waitFor(
                "typeof window.releaseMetadataEncryption === 'function'",
            );
            await evaluate(
                `{ const input = document.querySelector('input[placeholder="Add a tag…"]'); input.value = 'private-concurrent-tag'; input.dispatchEvent(new Event('input', { bubbles: true })); }`,
            );
            await click('Add');
            await waitFor(
                "document.body.innerText.includes('private-concurrent-tag')",
            );
            await evaluate('window.releaseMetadataEncryption()');
            for (let attempt = 0; attempt < 200 && conflicts < 1; attempt++)
                await new Promise((resolve) => setTimeout(resolve, 30));
            assert.equal(
                conflicts,
                1,
                'the stale description must conflict instead of overwriting the successful tag',
            );
            await click('Close');
            const beforeSearch = requests.length;
            await evaluate(
                `{ const input = document.querySelector('[data-test=files-search]'); input.value = 'private-secret'; input.dispatchEvent(new Event('input', { bubbles: true })); }`,
            );
            await waitFor(
                "Array.from(document.querySelectorAll('button')).some(b => b.textContent.trim() === 'private-secret.txt')",
            );
            await new Promise((resolve) => setTimeout(resolve, 450));
            assert.equal(
                requests
                    .slice(beforeSearch)
                    .filter(
                        ({ url }) =>
                            url === '/files' || url.startsWith('/files?'),
                    ).length,
                0,
            );
            await evaluate(`{
                const decrypt = crypto.subtle.decrypt.bind(crypto.subtle);
                crypto.subtle.decrypt = async (...args) => {
                    if (window.holdMetadataDecryption && new TextDecoder().decode(args[0].additionalData ?? new Uint8Array()).includes(':metadata:')) {
                        window.holdMetadataDecryption = false;
                        await new Promise(resolve => { window.releaseMetadataDecryption = resolve; });
                    }
                    return decrypt(...args);
                };
                window.holdMetadataDecryption = true;
                window.testRouter.reload({ only: ['files'] });
            }`);
            await waitFor(
                "typeof window.releaseMetadataDecryption === 'function'",
            );
            await evaluate(
                `{ const button = document.querySelector('[data-test=entry-menu-button]'); button.click(); }`,
            );
            await waitFor(
                "Array.from(document.querySelectorAll('[role=menuitem]')).some(b => b.textContent.includes('Rename'))",
            );
            await evaluate(
                "Array.from(document.querySelectorAll('[role=menuitem]')).find(b => b.textContent.includes('Rename')).click()",
            );
            await waitFor("document.getElementById('rename-name') !== null");
            await fill('rename-name', 'renamed-private.txt');
            await click('Save');
            await waitFor("document.getElementById('rename-name') === null");
            await evaluate('window.releaseMetadataDecryption()');
            await new Promise((resolve) => setTimeout(resolve, 150));
            await evaluate(
                `{ const input = document.querySelector('[data-test=files-search]'); input.value = ''; input.dispatchEvent(new Event('input', { bubbles: true })); }`,
            );
            await waitFor(
                "Array.from(document.querySelectorAll('button')).some(b => b.textContent.trim() === 'renamed-private.txt')",
            );
            await evaluate(
                `{ const button = document.querySelector('[data-test=entry-menu-button]'); button.click(); }`,
            );
            await waitFor(
                "Array.from(document.querySelectorAll('[role=menuitem]')).some(b => b.textContent.includes('Download'))",
            );
            await evaluate(
                "Array.from(document.querySelectorAll('[role=menuitem]')).find(b => b.textContent.trim() === 'Download').click()",
            );
            let exported;
            for (let i = 0; i < 100; i++) {
                exported = await readFile(
                    path.join(directory, 'renamed-private.txt'),
                ).catch(() => undefined);
                if (exported) break;
                await new Promise((resolve) => setTimeout(resolve, 30));
            }
            assert.equal(exported?.toString(), 'private file content');
            for (const request of requests) {
                for (const secret of [
                    'private-secret',
                    'renamed-private',
                    'private description',
                    'private-tag',
                    'private concurrent',
                    'private-concurrent-tag',
                    'private file content',
                ]) {
                    assert.ok(!request.url.includes(secret), request.url);
                    assert.ok(
                        !request.body.includes(secret),
                        `${request.method} ${request.url} leaked ${secret}`,
                    );
                }
            }
            assert.ok(
                requests.filter(
                    (request) => request.url === '/files/1/protected-metadata',
                ).length >= 3,
            );
            await evaluate(
                "localStorage.setItem('roxifile.browser-retain.7', 'yes')",
            );
            const { targetId: logoutTargetId } = await send(
                'Target.createTarget',
                { url: 'about:blank' },
            );
            const { sessionId: logoutSessionId } = await send(
                'Target.attachToTarget',
                { targetId: logoutTargetId, flatten: true },
            );
            await send('Runtime.enable', {}, logoutSessionId);
            await send('Page.enable', {}, logoutSessionId);
            await send(
                'Page.navigate',
                { url: `${origin}/__logout_test` },
                logoutSessionId,
            );
            await waitForIn('window.ready === true', logoutSessionId);
            await evaluateIn('window.logout()', logoutSessionId);
            await waitFor(
                "!document.body.innerText.includes('renamed-private.txt') && !document.body.innerText.includes('Unlocked on this page')",
            );
            assert.equal(
                await evaluate(
                    "document.body.innerText.includes('private-tag') || document.body.innerText.includes('private description')",
                ),
                false,
            );
            assert.equal(
                await evaluate(
                    "localStorage.getItem('roxifile.browser-retain.7') === 'yes' && localStorage.getItem('roxifile.browser-account') === '7'",
                ),
                true,
            );
            await fill('vault-unlock-19', 'separate vault password');
            await click('Unlock on this device');
            await waitFor(
                "Array.from(document.querySelectorAll('button')).some(b => b.textContent.trim() === 'renamed-private.txt')",
            );
            userId = 8;
            await evaluate("window.testRouter.reload({ only: ['auth'] })");
            await waitFor(
                "!document.body.innerText.includes('renamed-private.txt')",
            );
            await waitFor(
                "document.body.innerText.includes('encrypted · Locked')",
            );
            assert.equal(
                await evaluate(
                    "document.body.innerText.includes('private-tag')",
                ),
                false,
            );
            userId = 7;
            await evaluate("window.testRouter.reload({ only: ['auth'] })");
            await waitFor(
                "document.getElementById('vault-unlock-19') !== null",
            );
            await fill('vault-unlock-19', 'separate vault password');
            await click('Unlock on this device');
            await waitFor(
                "Array.from(document.querySelectorAll('button')).some(b => b.textContent.trim() === 'renamed-private.txt')",
            );
            await click('Lock vault and cancel local transfers');
            await waitFor(
                "!document.body.innerText.includes('renamed-private.txt')",
            );
            assert.equal(
                await evaluate(
                    "document.body.innerText.includes('private-tag')",
                ),
                false,
            );
            assert.equal(
                await evaluate(
                    "document.body.innerText.includes('private description')",
                ),
                false,
            );
            await evaluate('window.testRouter.reload()');
            await waitFor(
                "document.body.innerText.includes('encrypted · Locked')",
            );
            assert.equal(
                await evaluate(
                    "document.body.innerText.includes('renamed-private.txt')",
                ),
                false,
            );
        } finally {
            socket?.close();
            chrome?.kill();
            await server.close();
            if (chrome && chrome.exitCode === null) await once(chrome, 'exit');
            await rm(directory, {
                recursive: true,
                force: true,
                maxRetries: 10,
                retryDelay: 50,
            });
        }
    },
);
