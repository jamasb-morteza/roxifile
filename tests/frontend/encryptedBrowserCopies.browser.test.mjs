import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, readFile, readdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { once } from 'node:events';

void test(
    'encrypted browser copies unlock, preview and export in Chromium without exposing protected catalog fields',
    { skip: !process.env.CHROME_BIN, timeout: 240000 },
    async () => {
        const { createServer } = await import('vite');
        const { default: vue } = await import('@vitejs/plugin-vue');
        const root = path.resolve(import.meta.dirname, '../..');
        const expectedPng = Buffer.from(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC',
            'base64',
        );
        const directory = await mkdtemp(
            path.join(tmpdir(), 'roxifile-encrypted-browser-test-'),
        );
        const server = await createServer({
            configFile: false,
            root,
            cacheDir: path.join(directory, 'vite-cache'),
            optimizeDeps: { noDiscovery: true },
            resolve: { alias: { '@': path.join(root, 'resources/js') } },
            plugins: [vue()],
            server: { host: '127.0.0.1', port: 0, watch: null },
        });
        server.middlewares.stack.unshift({
            route: '',
            handle: async (req, res, next) => {
                if (
                    req.url.startsWith('/build/') ||
                    req.url === '/browser-downloads-sw.js'
                ) {
                    const target = path.join(
                        root,
                        'public',
                        new URL(req.url, 'http://localhost').pathname,
                    );
                    const type = target.endsWith('.html')
                        ? 'text/html'
                        : target.endsWith('.js')
                          ? 'text/javascript'
                          : target.endsWith('.css')
                            ? 'text/css'
                            : 'application/octet-stream';
                    res.setHeader('Content-Type', type);
                    res.end(await readFile(target));
                    return;
                }
                if (req.url !== '/__encrypted_test') return next();
                res.setHeader('Content-Type', 'text/html');
                res.end(
                    await server.transformIndexHtml(
                        req.url,
                        '<html><body><main id="app"></main><script type="module" src="/tests/frontend/encryptedBrowserHarness.js"></script></body></html>',
                    ),
                );
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
                    '--autoplay-policy=no-user-gesture-required',
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
                if (!callback) return;
                pending.delete(response.id);
                if (response.error)
                    callback.reject(new Error(response.error.message));
                else callback.resolve(response.result);
            });
            const send = (method, params = {}, sessionId) => {
                const id = ++sequence;
                return new Promise((resolve, reject) => {
                    pending.set(id, { resolve, reject });
                    socket.send(
                        JSON.stringify({ id, method, params, sessionId }),
                    );
                });
            };
            socket.addEventListener('message', (event) => {
                const message = JSON.parse(event.data);
                if (
                    [
                        'Runtime.exceptionThrown',
                        'Network.loadingFailed',
                        'Runtime.consoleAPICalled',
                    ].includes(message.method)
                ) {
                    process.stderr.write(`${JSON.stringify(message)}\n`);
                }
            });
            const { targetId } = await send('Target.createTarget', {
                url: 'about:blank',
            });
            let { sessionId } = await send('Target.attachToTarget', {
                targetId,
                flatten: true,
            });
            await send('Runtime.enable', {}, sessionId);
            await send('Network.enable', {}, sessionId);
            async function evaluate(expression) {
                const response = await send(
                    'Runtime.evaluate',
                    { expression, awaitPromise: true, returnByValue: true },
                    sessionId,
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
                    `Timed out: ${expression}; ${await evaluate('(async()=>JSON.stringify({url:location.href,readyState:document.readyState,error:window.bootError,body:document.body.innerText,html:document.documentElement?.outerHTML,controller:!!navigator.serviceWorker?.controller,registrations:await navigator.serviceWorker?.getRegistrations().then(rows=>rows.map(row=>({active:row.active?.state,script:row.active?.scriptURL}))),caches:await caches.keys(),resources:performance.getEntriesByType("resource").map(entry=>entry.name)}))()')}`,
                );
            }
            await send(
                'Page.navigate',
                { url: `${origin}/__encrypted_test` },
                sessionId,
            );
            await waitFor('window.ready === true');
            assert.equal(
                await evaluate(
                    'document.body.innerText.includes("secret-photo.png")',
                ),
                false,
            );
            assert.equal(
                await evaluate('document.body.innerText.includes("raw_key")'),
                false,
            );
            await evaluate(
                `(async()=>{const card=Array.from(document.querySelectorAll('[data-test="browser-copy"]')).find(row=>row.innerText.includes('Protected file'));const field=card.querySelector('input[type=password]');field.value='separate offline vault password';field.dispatchEvent(new Event('input',{bubbles:true}));Array.from(card.querySelectorAll('button')).find(button=>button.textContent.trim()==='Unlock saved copy').click();window.workflow.setBrowserAccount(8);})()`,
            );
            await new Promise((resolve) => setTimeout(resolve, 1200));
            assert.equal(
                await evaluate(
                    'document.body.innerText.includes("secret-photo.png")',
                ),
                false,
                'switching accounts while unlock is pending must not reveal protected details',
            );
            await evaluate('window.workflow.setBrowserAccount(7)');
            assert.equal(
                await evaluate(
                    'document.body.innerText.includes("visible-photo.png") && document.body.innerText.includes("offline-tone.wav")',
                ),
                true,
            );
            await evaluate(
                `(async()=>{const field=document.querySelector('input[type=password]');field.value='wrong password';field.dispatchEvent(new Event('input',{bubbles:true}));Array.from(document.querySelectorAll('button')).find(button=>button.textContent.trim()==='Unlock saved copy').click();await new Promise(resolve=>setTimeout(resolve,300));})()`,
            );
            assert.equal(
                await evaluate(
                    'document.body.innerText.includes("wrong credential")',
                ),
                true,
            );
            await evaluate(
                `(async()=>{const field=document.querySelector('input[type=password]');field.value='separate offline vault password';field.dispatchEvent(new Event('input',{bubbles:true}));Array.from(document.querySelectorAll('button')).find(button=>button.textContent.trim()==='Unlock saved copy').click();})()`,
            );
            await waitFor(
                'document.body.innerText.includes("secret-photo.png")',
            );
            assert.equal(
                await evaluate(
                    'document.body.innerText.includes("offline description")',
                ),
                true,
            );
            await evaluate(
                `(async()=>{const field=document.querySelector('input[type=password]');field.value='separate offline vault password';field.dispatchEvent(new Event('input',{bubbles:true}));Array.from(document.querySelectorAll('button')).find(button=>button.textContent.trim()==='Unlock saved copy').click();})()`,
            );
            await waitFor(
                `Array.from(document.querySelectorAll('[data-test="browser-copy"]')).filter(row=>row.innerText.includes('Protected file')||row.innerText.includes('visible-photo.png')).every(row=>row.querySelectorAll('input[type=password]').length===0)`,
            );
            await evaluate(
                `Array.from(document.querySelectorAll('button')).find(button=>button.textContent.trim()==='Open media').click()`,
            );
            await waitFor(
                `document.querySelector('img[alt="secret-photo.png"]')?.naturalWidth === 1`,
            );
            await send('Browser.setDownloadBehavior', {
                behavior: 'allow',
                downloadPath: directory,
            });
            await evaluate(
                `Array.from(document.querySelectorAll('button')).find(button=>button.textContent.trim()==='Download decrypted file').click()`,
            );
            let exported;
            for (let attempt = 0; attempt < 200; attempt++) {
                try {
                    exported = await readFile(
                        path.join(directory, 'secret-photo.png'),
                    );
                    break;
                } catch {
                    await new Promise((resolve) => setTimeout(resolve, 30));
                }
            }
            assert.ok(exported?.byteLength > 0);
            assert.deepEqual(exported, expectedPng);
            await rm(path.join(directory, 'secret-photo.png'));
            await evaluate(
                `Array.from(document.querySelectorAll('button')).find(button=>button.textContent.trim()==='Lock').click()`,
            );
            assert.equal(
                await evaluate(
                    'document.body.innerText.includes("secret-photo.png")',
                ),
                false,
            );
            await evaluate('workflow.installOfflineShell()');
            const cachedPaths = await evaluate(
                `(async()=>{const cache=await caches.open('roxifile-offline-v1');return (await cache.keys()).map(request=>new URL(request.url).pathname);})()`,
            );
            assert.ok(cachedPaths.includes('/build/resources/offline.html'));
            assert.ok(
                cachedPaths.every(
                    (url) =>
                        url === '/build/resources/offline.html' ||
                        url.startsWith('/build/assets/'),
                ),
            );
            await send('Browser.close');
            await once(chrome, 'exit');
            socket.close();
            socket = undefined;
            chrome = spawn(
                process.env.CHROME_BIN,
                [
                    '--no-sandbox',
                    '--headless',
                    '--disable-gpu',
                    '--autoplay-policy=no-user-gesture-required',
                    '--remote-debugging-port=0',
                    `--user-data-dir=${path.join(directory, 'profile')}`,
                    'about:blank',
                ],
                { stdio: ['ignore', 'ignore', 'pipe'] },
            );
            const restartedEndpoint = await new Promise((resolve, reject) => {
                let output = '';
                chrome.once('error', reject);
                chrome.stderr.on('data', (chunk) => {
                    output += chunk;
                    const match = output.match(
                        /DevTools listening on (ws:\/\/[^\s]+)/,
                    );
                    if (match) resolve(match[1]);
                });
            });
            socket = new WebSocket(restartedEndpoint);
            await once(socket, 'open');
            socket.addEventListener('message', (event) => {
                const response = JSON.parse(event.data);
                const callback = pending.get(response.id);
                if (!callback) return;
                pending.delete(response.id);
                if (response.error)
                    callback.reject(new Error(response.error.message));
                else callback.resolve(response.result);
            });
            socket.addEventListener('message', (event) => {
                const message = JSON.parse(event.data);
                if (
                    [
                        'Runtime.exceptionThrown',
                        'Network.loadingFailed',
                        'Runtime.consoleAPICalled',
                    ].includes(message.method)
                ) {
                    process.stderr.write(`${JSON.stringify(message)}\n`);
                }
            });
            const restartedTarget = await send('Target.createTarget', {
                url: 'about:blank',
            });
            ({ sessionId } = await send('Target.attachToTarget', {
                targetId: restartedTarget.targetId,
                flatten: true,
            }));
            await send('Runtime.enable', {}, sessionId);
            await send('Network.enable', {}, sessionId);
            await send('Page.enable', {}, sessionId);
            await send(
                'Network.emulateNetworkConditions',
                {
                    offline: true,
                    latency: 0,
                    downloadThroughput: 0,
                    uploadThroughput: 0,
                },
                sessionId,
            );
            const navigation = await send(
                'Page.navigate',
                { url: `${origin}/build/resources/offline.html` },
                sessionId,
            );
            assert.equal(
                navigation.errorText,
                undefined,
                JSON.stringify(navigation),
            );
            await waitFor(
                `document.querySelectorAll('[data-test="browser-copy"]').length === 4`,
            );
            assert.equal(
                await evaluate(
                    'document.body.innerText.includes("Protected file") && document.body.innerText.includes("visible-photo.png") && document.body.innerText.includes("offline-tone.wav") && document.body.innerText.includes("offline-clip.webm") && !document.body.innerText.includes("secret-photo.png")',
                ),
                true,
            );
            const protectedCard = `Array.from(document.querySelectorAll('[data-test="browser-copy"]')).find(row=>row.innerText.includes('Protected file'))`;
            await evaluate(
                `(async()=>{const card=${protectedCard};const field=card.querySelector('input[type=password]');field.value='wrong password';field.dispatchEvent(new Event('input',{bubbles:true}));Array.from(card.querySelectorAll('button')).find(button=>button.textContent.trim()==='Unlock saved copy').click();})()`,
            );
            await waitFor(
                'document.body.innerText.includes("wrong credential")',
            );
            await evaluate(
                `(async()=>{const card=${protectedCard};const field=card.querySelector('input[type=password]');field.value='separate offline vault password';field.dispatchEvent(new Event('input',{bubbles:true}));Array.from(card.querySelectorAll('button')).find(button=>button.textContent.trim()==='Unlock saved copy').click();})()`,
            );
            await waitFor(
                'document.body.innerText.includes("secret-photo.png")',
            );
            await evaluate(
                `(async()=>{const card=${protectedCard};Array.from(card.querySelectorAll('button')).find(button=>button.textContent.trim()==='Open media').click();})()`,
            );
            await waitFor(
                `document.querySelector('img[alt="secret-photo.png"]')?.naturalWidth === 1`,
            );
            await send('Browser.setDownloadBehavior', {
                behavior: 'allow',
                downloadPath: directory,
            });
            await evaluate(
                `(async()=>{const card=${protectedCard};Array.from(card.querySelectorAll('button')).find(button=>button.textContent.trim()==='Download decrypted file').click();})()`,
            );
            exported = undefined;
            for (let attempt = 0; attempt < 200; attempt++) {
                try {
                    exported = await readFile(
                        path.join(directory, 'secret-photo.png'),
                    );
                    break;
                } catch {
                    await new Promise((resolve) => setTimeout(resolve, 30));
                }
            }
            assert.ok(exported?.byteLength > 0);
            assert.deepEqual(exported, expectedPng);
            await rm(path.join(directory, 'secret-photo.png'));
            await evaluate(
                `(()=>{const original=crypto.subtle.decrypt.bind(crypto.subtle);window.originalSubtleDecrypt=original;window.decryptStarted=false;window.releaseDecrypt=undefined;crypto.subtle.decrypt=(...args)=>{window.decryptStarted=true;return new Promise((resolve,reject)=>{window.releaseDecrypt=()=>original(...args).then(resolve,reject);});};})()`,
            );
            await evaluate(
                `(async()=>{const card=${protectedCard};Array.from(card.querySelectorAll('button')).find(button=>button.textContent.trim()==='Download decrypted file').click();})()`,
            );
            await waitFor('window.decryptStarted === true');
            await evaluate(
                `(async()=>{const card=${protectedCard};Array.from(card.querySelectorAll('button')).find(button=>button.textContent.trim()==='Lock').click();window.releaseDecrypt();crypto.subtle.decrypt=window.originalSubtleDecrypt;})()`,
            );
            await new Promise((resolve) => setTimeout(resolve, 500));
            assert.deepEqual(
                (await readdir(directory, { withFileTypes: true }))
                    .filter((entry) => entry.isFile())
                    .map((entry) => entry.name),
                [],
                'locking during a pending decrypt must not export plaintext',
            );
            assert.equal(
                await evaluate(
                    'document.body.innerText.includes("secret-photo.png")',
                ),
                false,
            );
            await evaluate(
                `(async()=>{const card=${protectedCard};const field=card.querySelector('input[type=password]');field.value='separate offline vault password';field.dispatchEvent(new Event('input',{bubbles:true}));Array.from(card.querySelectorAll('button')).find(button=>button.textContent.trim()==='Unlock saved copy').click();})()`,
            );
            await waitFor(
                `${protectedCard}?.innerText.includes('Unlocked in memory')`,
            );
            const audioCard = `Array.from(document.querySelectorAll('[data-test="browser-copy"]')).find(row=>row.innerText.includes('offline-tone.wav'))`;
            await evaluate(
                `(async()=>{const card=${audioCard};const field=card.querySelector('input[type=password]');field.value='separate offline vault password';field.dispatchEvent(new Event('input',{bubbles:true}));Array.from(card.querySelectorAll('button')).find(button=>button.textContent.trim()==='Unlock saved copy').click();})()`,
            );
            await waitFor(
                `${audioCard}?.innerText.includes('Unlocked in memory')`,
            );
            await evaluate(
                `(async()=>{const card=${audioCard};Array.from(card.querySelectorAll('button')).find(button=>button.textContent.trim()==='Open media').click();})()`,
            );
            await waitFor(
                `document.querySelector('[data-test="media-viewer"] audio')?.readyState >= 2`,
            );
            assert.equal(
                await evaluate(
                    `document.querySelector('[data-test="media-viewer"] audio').paused`,
                ),
                true,
                'the decrypted WAV should be ready and paused before playback',
            );
            await evaluate(
                `document.querySelector('[data-test="media-viewer"] audio').play()`,
            );
            await waitFor(
                `(()=>{const audio=document.querySelector('[data-test="media-viewer"] audio');return audio && (audio.currentTime > 0 || audio.ended);})()`,
            );
            assert.equal(
                await evaluate(
                    `document.querySelector('[data-test="media-viewer"] audio').error === null`,
                ),
                true,
                'the decrypted WAV should decode and play after an offline browser restart',
            );
            await evaluate(
                `document.querySelector('[data-test="media-viewer"] audio').pause()`,
            );
            assert.equal(
                await evaluate(
                    `document.querySelector('[data-test="media-viewer"] audio').paused`,
                ),
                true,
                'the decrypted WAV should pause on request',
            );
            await evaluate(
                `(async()=>{const card=${audioCard};Array.from(card.querySelectorAll('button')).find(button=>button.textContent.trim()==='Lock').click();})()`,
            );
            const videoCard = `Array.from(document.querySelectorAll('[data-test="browser-copy"]')).find(row=>row.innerText.includes('offline-clip.webm'))`;
            await evaluate(
                `(async()=>{const card=${videoCard};const field=card.querySelector('input[type=password]');field.value='separate offline vault password';field.dispatchEvent(new Event('input',{bubbles:true}));Array.from(card.querySelectorAll('button')).find(button=>button.textContent.trim()==='Unlock saved copy').click();})()`,
            );
            await waitFor(
                `${videoCard}?.innerText.includes('Unlocked in memory')`,
            );
            await evaluate(
                `(async()=>{const card=${videoCard};Array.from(card.querySelectorAll('button')).find(button=>button.textContent.trim()==='Open media').click();})()`,
            );
            await waitFor(
                `(()=>{const video=document.querySelector('[data-test="media-viewer"] video');return video && video.readyState >= 2 && video.duration > 0 && video.videoWidth > 0;})()`,
            );
            assert.equal(
                await evaluate(
                    `document.querySelector('[data-test="media-viewer"] video').paused`,
                ),
                true,
                'the decrypted WebM should load metadata and be ready before playback',
            );
            await evaluate(
                `document.querySelector('[data-test="media-viewer"] video').play()`,
            );
            await waitFor(
                `(()=>{const video=document.querySelector('[data-test="media-viewer"] video');return video && !video.paused && (video.currentTime > 0 || video.ended);})()`,
            );
            await evaluate(
                `document.querySelector('[data-test="media-viewer"] video').pause()`,
            );
            assert.equal(
                await evaluate(
                    `document.querySelector('[data-test="media-viewer"] video').paused`,
                ),
                true,
                'the decrypted WebM should pause on request',
            );
            await evaluate(
                'window.savedVideo = document.querySelector("video")',
            );
            await evaluate(
                `(async()=>{const card=${videoCard};Array.from(card.querySelectorAll('button')).find(button=>button.textContent.trim()==='Lock').click();})()`,
            );
            await waitFor(
                `document.querySelector('[data-test="media-viewer"] video') === null`,
            );
            assert.equal(
                await evaluate('window.savedVideo.paused'),
                true,
                'locking the saved copy should stop its video',
            );
            assert.equal(
                await evaluate('window.savedVideo.hasAttribute("src")'),
                false,
                'locking the saved copy should release its decrypted media URL',
            );
            await evaluate(
                `(async()=>{const card=${protectedCard};Array.from(card.querySelectorAll('button')).find(button=>button.textContent.trim()==='Lock').click();})()`,
            );
            assert.equal(
                await evaluate(
                    'document.body.innerText.includes("secret-photo.png")',
                ),
                false,
            );
            await evaluate(
                `(()=>{localStorage.setItem('roxifile.browser-account','8');window.dispatchEvent(new StorageEvent('storage',{key:'roxifile.browser-account',newValue:'8'}));})()`,
            );
            await waitFor(
                `document.querySelectorAll('[data-test="browser-copy"]').length === 0`,
            );
            assert.equal(
                await evaluate(
                    `document.body.innerText.includes('No complete browser copies for this account.')`,
                ),
                true,
                'account 8 must not list account 7 offline copies',
            );
            assert.equal(
                await evaluate(
                    `['Protected file','visible-photo.png','offline-tone.wav','offline-clip.webm','Unlock saved copy','Open media','Download decrypted file','Preview','Download saved copy'].some(text=>document.body.innerText.includes(text))`,
                ),
                false,
                'account 8 must not expose account 7 copy details or preview/unlock/download actions',
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
                retryDelay: 100,
            });
        }
    },
);
