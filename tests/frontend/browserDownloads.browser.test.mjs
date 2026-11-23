import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, rm, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { once } from 'node:events';
void test(
    'browser snapshots survive restart offline and preserve complete copies through failures',
    { skip: !process.env.CHROME_BIN, timeout: 240000 },
    async () => {
        const { createServer } = await import('vite');
        const root = path.resolve(import.meta.dirname, '../..');
        const directory = await mkdtemp(
            path.join(tmpdir(), 'roxifile-offline-test-'),
        );
        const server = await createServer({
            configFile: false,
            root,
            cacheDir: path.join(directory, 'vite-cache'),
            optimizeDeps: { noDiscovery: true },
            resolve: { alias: { '@': path.join(root, 'resources/js') } },
            server: { host: '127.0.0.1', port: 0, watch: null },
        });
        let missingOriginal = false;
        let preparedBytes = 'hello';
        const audio = Buffer.alloc(16_044, 128);
        audio.write('RIFF', 0);
        audio.writeUInt32LE(16_036, 4);
        audio.write('WAVEfmt ', 8);
        audio.writeUInt32LE(16, 16);
        audio.writeUInt16LE(1, 20);
        audio.writeUInt16LE(1, 22);
        audio.writeUInt32LE(8_000, 24);
        audio.writeUInt32LE(8_000, 28);
        audio.writeUInt16LE(1, 32);
        audio.writeUInt16LE(8, 34);
        audio.write('data', 36);
        audio.writeUInt32LE(16_000, 40);
        server.middlewares.stack.unshift({
            route: '',
            handle: async (req, res, next) => {
                if (
                    req.url.startsWith('/build/') ||
                    req.url === '/browser-downloads-sw.js'
                ) {
                    try {
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
                    } catch {
                        res.statusCode = 404;
                        res.end();
                    }
                    return;
                }

                if (req.url.startsWith('/files?')) {
                    const file = Number(
                        new URL(req.url, 'http://localhost').searchParams.get(
                            'file',
                        ),
                    );
                    const isAudio = file === 4 || file === 6;
                    res.statusCode = missingOriginal || file === 99 ? 404 : 200;
                    res.setHeader('Content-Type', 'application/json');
                    res.end(
                        JSON.stringify({
                            selected_file: {
                                id: file,
                                name: isAudio ? 'saved.wav' : 'saved.txt',
                                mime_type: isAudio ? 'audio/wav' : 'text/plain',
                                size: isAudio ? audio.length : 5,
                                directory_path: 'Saved',
                                description: null,
                                tags: [],
                                encryption_header: null,
                                metadata_protected: false,
                            },
                        }),
                    );
                    return;
                }
                if (/^\/files\/(1|4|6)\/prepare-download$/.test(req.url)) {
                    res.setHeader('Content-Type', 'application/json');
                    res.end('{"operation_id":88,"status":"completed"}');
                    return;
                }
                if (/^\/files\/(1|4|6)\/download[?]/.test(req.url)) {
                    res.setHeader('Content-Type', 'application/octet-stream');
                    res.setHeader('Cache-Control', 'private, no-store');
                    res.end(
                        req.url.startsWith('/files/1/') ? preparedBytes : audio,
                    );
                    return;
                }
                if (req.url !== '/__browser_test') return next();
                res.setHeader('Content-Type', 'text/html');
                res.end(
                    await server.transformIndexHtml(
                        req.url,
                        `<html><body><script type="module">
window.workflow=await import('/resources/js/lib/browserDownloads.ts');
window.transfer=await import('/resources/js/lib/browserDownloadTransfer.ts');
window.workflow.setBrowserAccount(7);
window.snapshot={id:1,name:'saved.txt',mime_type:'text/plain',size:5,directory_path:'Saved',description:null,tags:[]};
window.ready=true;
</script></body></html>`,
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
                if (response.method === 'Runtime.exceptionThrown')
                    process.stderr.write(
                        JSON.stringify(response.params) + '\n',
                    );
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
                    const timeout = setTimeout(() => {
                        pending.delete(id);
                        reject(new Error(`CDP timeout: ${method}`));
                    }, 120000);
                    pending.set(id, {
                        resolve: (value) => {
                            clearTimeout(timeout);
                            resolve(value);
                        },
                        reject: (error) => {
                            clearTimeout(timeout);
                            reject(error);
                        },
                    });
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
            await send('Runtime.enable', {}, sessionId);
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
                    `Timed out: ${expression}; ${await evaluate('document.body.innerText')}`,
                );
            }
            async function open() {
                await send(
                    'Page.navigate',
                    { url: `${origin}/__browser_test` },
                    sessionId,
                );
                await waitFor('window.ready === true');
            }
            await open();
            await evaluate('workflow.installOfflineShell()');
            const saved = await evaluate(
                `(async()=>{Object.defineProperty(navigator.storage,'persist',{configurable:true,value:async()=>false});const result=await transfer.downloadToBrowser(7,1,new AbortController().signal,()=>{});return {text:await (await workflow.readBrowserCopy(7,1)).text(),rows:await workflow.listBrowserCopies(7),persistent:result.persistent};})()`,
            );
            assert.equal(saved.text, 'hello');
            assert.equal(saved.rows.length, 1);
            assert.equal(saved.rows[0].available, true);
            assert.ok(saved.rows[0].saved_at);
            assert.equal(saved.persistent, false);
            await evaluate(
                `(async()=>{const bytes=Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP6sAAAAASUVORK5CYII='),char=>char.charCodeAt(0));await workflow.saveBrowserCopy(7,{id:3,name:'saved.png',mime_type:'image/png',size:bytes.length,directory_path:'',description:null,tags:[]},new Response(bytes),new AbortController().signal,()=>{});return true;})()`,
            );
            const originalTime = saved.rows.find(
                (row) => row.id === 1,
            ).saved_at;
            assert.equal(
                await evaluate(
                    `(async()=>{try{await workflow.saveBrowserCopy(7,{...snapshot,name:'bad.txt'},new Response('cut'),new AbortController().signal,()=>{});return false;}catch{return true;}})()`,
                ),
                true,
            );
            assert.equal(
                await evaluate(
                    '(async()=>{return (await workflow.listBrowserCopies(7)).find(row=>row.id===1).saved_at;})()',
                ),
                originalTime,
            );
            assert.equal(
                await evaluate(
                    '(async()=>await (await workflow.readBrowserCopy(7,1)).text())()',
                ),
                'hello',
            );
            await evaluate('workflow.setBrowserAccount(8)');
            assert.deepEqual(
                await evaluate('workflow.listBrowserCopies(8)'),
                [],
            );
            assert.equal(
                await evaluate(
                    '(async()=>{try{await workflow.readBrowserCopy(7,1);return false;}catch{return true;}})()',
                ),
                true,
            );
            await evaluate(
                'workflow.setBrowserAccount(7);workflow.retainBrowserCopies(7,true)',
            );
            await evaluate('workflow.logoutBrowserCopies(7)');
            assert.equal(
                await evaluate(
                    '(async()=>await (await workflow.readBrowserCopy(7,1)).text())()',
                ),
                'hello',
            );
            await send(
                'Storage.overrideQuotaForOrigin',
                { origin, quotaSize: 1 },
                sessionId,
            );
            assert.equal(
                await evaluate(
                    `(async()=>{try{await workflow.saveBrowserCopy(7,{...snapshot,size:100000},new Response(new Uint8Array(100000)),new AbortController().signal,()=>{});return false;}catch(error){return error.name==='QuotaExceededError';}})()`,
                ),
                true,
            );
            await send('Storage.overrideQuotaForOrigin', { origin }, sessionId);
            assert.equal(
                await evaluate(
                    '(async()=>await (await workflow.readBrowserCopy(7,1)).text())()',
                ),
                'hello',
            );
            assert.equal(
                await evaluate(
                    `(async()=>{const controller=new AbortController();controller.abort();try{await workflow.saveBrowserCopy(7,snapshot,new Response('other'),controller.signal,()=>{});return false;}catch(error){return error.name==='AbortError';}})()`,
                ),
                true,
            );
            assert.equal(
                await evaluate(
                    `(async()=>{const controller=new AbortController();const stream=new ReadableStream({start(source){source.enqueue(new TextEncoder().encode('ab'));source.enqueue(new TextEncoder().encode('cde'));source.close();}});try{await workflow.saveBrowserCopy(7,snapshot,new Response(stream),controller.signal,()=>controller.abort());return false;}catch(error){return error.name==='AbortError' && await (await workflow.readBrowserCopy(7,1)).text()==='hello';}})()`,
                ),
                true,
            );
            assert.equal(
                await evaluate(
                    `(async()=>{Object.defineProperty(navigator.storage,'getDirectory',{configurable:true,value:undefined});try{await workflow.saveBrowserCopy(7,snapshot,new Response('hello'),new AbortController().signal,()=>{});return false;}catch(error){return !workflow.browserStorageAvailable()&&error.message.includes('regular download');}finally{delete navigator.storage.getDirectory;}})()`,
                ),
                true,
            );
            await evaluate(
                `(async()=>{await workflow.saveBrowserCopy(7,{...snapshot,id:2},new Response('hello'),new AbortController().signal,()=>{});const row=(await workflow.listBrowserCopies(7)).find(row=>row.id===2);const root=await navigator.storage.getDirectory();const folder=await (await root.getDirectoryHandle('roxifile-browser-copies')).getDirectoryHandle('7');await folder.removeEntry(row.path);})()`,
            );
            assert.equal(
                await evaluate(
                    `(async()=>{const row=(await workflow.listBrowserCopies(7)).find(row=>row.id===2);try{await workflow.readBrowserCopy(7,2);return false;}catch(error){return !row.available&&error.message.includes('evicted');}})()`,
                ),
                true,
            );
            await evaluate('workflow.removeBrowserCopy(7,2)');
            await evaluate('workflow.retainBrowserCopies(7,false)');
            await evaluate('workflow.logoutBrowserCopies(7)');
            assert.equal(await evaluate('workflow.browserAccount()'), null);
            await evaluate('workflow.setBrowserAccount(7)');
            assert.deepEqual(
                await evaluate('workflow.listBrowserCopies(7)'),
                [],
            );
            await evaluate(
                `workflow.saveBrowserCopy(7,snapshot,new Response('hello'),new AbortController().signal,()=>{})`,
            );
            await evaluate(
                `(async()=>{const bytes=Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP6sAAAAASUVORK5CYII='),char=>char.charCodeAt(0));await workflow.saveBrowserCopy(7,{id:3,name:'saved.png',mime_type:'image/png',size:bytes.length,directory_path:'',description:null,tags:[]},new Response(bytes),new AbortController().signal,()=>{});return true;})()`,
            );
            const batch = await evaluate(`(async()=>{
                const { downloadMediaBatch } = await import('/resources/js/lib/mediaDownloads.ts');
                const controller = new AbortController();
                const result = await downloadMediaBatch(
                    [4, 99, 6], new Set(), controller.signal,
                    (id, signal, progress) => transfer.downloadToBrowser(7, id, signal, progress),
                    ({ id, bytes }) => { if (id === 6 && bytes > 0) controller.abort(); },
                );
                return {
                    result,
                    savedAudio: (await workflow.readBrowserCopy(7, 4)).size,
                    interruptedCopy: (await workflow.listBrowserCopies(7)).some(copy => copy.id === 6),
                };
            })()`);
            assert.deepEqual(batch, {
                result: {
                    saved: [4],
                    failures: [
                        {
                            id: 99,
                            message:
                                'The original file is missing. Any saved browser copy is preserved.',
                        },
                    ],
                    cancelled: true,
                },
                savedAudio: 16044,
                interruptedCopy: false,
            });
            await evaluate(
                `(async()=>{const canvas=document.createElement('canvas');canvas.width=16;canvas.height=16;const stream=canvas.captureStream(10);const chunks=[];const recorder=new MediaRecorder(stream,{mimeType:'video/webm;codecs=vp8'});const finished=new Promise(resolve=>recorder.onstop=resolve);recorder.ondataavailable=event=>chunks.push(event.data);recorder.start();const context=canvas.getContext('2d');for(let index=0;index<5;index++){context.fillStyle=index%2?'red':'blue';context.fillRect(0,0,16,16);await new Promise(resolve=>setTimeout(resolve,100));}recorder.stop();await finished;stream.getTracks().forEach(track=>track.stop());const bytes=new Uint8Array(await new Blob(chunks,{type:'video/webm'}).arrayBuffer());await workflow.saveBrowserCopy(7,{id:5,name:'saved.webm',mime_type:'video/webm',size:bytes.length,directory_path:'',description:null,tags:[]},new Response(bytes),new AbortController().signal,()=>{});return bytes.length;})()`,
            );
            await evaluate('workflow.retainBrowserCopies(7,true)');
            await evaluate(
                `(async()=>{const {cacheMediaPlaylists}=await import('/resources/js/lib/browserDownloads.ts');cacheMediaPlaylists(7,[{id:1,name:'Saved mix',file_ids:[4]}]);})()`,
            );
            assert.equal(
                await evaluate(
                    `(async()=>{
                        const { cacheMediaPlaylists, cachedMediaPlaylists } = await import('/resources/js/lib/browserDownloads.ts');
                        cacheMediaPlaylists(7, []);
                        const savedAudio = await workflow.readBrowserCopy(7, 4);
                        const copyRemains = cachedMediaPlaylists(7).length === 0 && savedAudio.size === 16044;
                        cacheMediaPlaylists(7, [{ id: 1, name: 'Saved mix', file_ids: [4], folder_id: 99 }]);
                        const [playlist] = cachedMediaPlaylists(7);
                        return copyRemains && playlist.folder_id === 99 && playlist.file_ids[0] === 4;
                    })()`,
                ),
                true,
            );
            const cacheUrls = await evaluate(
                `(async()=>{const cache=await caches.open('roxifile-offline-v1');return (await cache.keys()).map(request=>new URL(request.url).pathname);})()`,
            );
            assert.ok(cacheUrls.includes('/build/resources/offline.html'));
            assert.ok(
                cacheUrls.every(
                    (url) =>
                        url === '/build/resources/offline.html' ||
                        url.startsWith('/build/assets/'),
                ),
            );
            missingOriginal = true;
            assert.equal(await evaluate('transfer.browserOriginal(1)'), null);
            await evaluate('workflow.markBrowserOriginalMissing(7,1,true)');
            // A fresh Chrome process with the same profile and no network proves persistent offline startup.
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
                if (callback) {
                    pending.delete(response.id);
                    if (response.error)
                        callback.reject(new Error(response.error.message));
                    else callback.resolve(response.result);
                }
            });
            const restartedTarget = await send('Target.createTarget', {
                url: 'about:blank',
            });
            const restartedSession = await send('Target.attachToTarget', {
                targetId: restartedTarget.targetId,
                flatten: true,
            });
            socket.addEventListener('message', (event) => {
                const response = JSON.parse(event.data);
                if (
                    [
                        'Runtime.exceptionThrown',
                        'Network.loadingFailed',
                        'Runtime.consoleAPICalled',
                    ].includes(response.method)
                )
                    process.stderr.write(JSON.stringify(response) + '\n');
            });
            await send('Runtime.enable', {}, restartedSession.sessionId);

            await send('Network.enable', {}, restartedSession.sessionId);
            await send(
                'Network.emulateNetworkConditions',
                {
                    offline: true,
                    latency: 0,
                    downloadThroughput: 0,
                    uploadThroughput: 0,
                },
                restartedSession.sessionId,
            );
            await send(
                'Page.navigate',
                { url: `${origin}/media` },
                restartedSession.sessionId,
            );
            async function offlineEvaluate(expression) {
                const result = await send(
                    'Runtime.evaluate',
                    { expression, awaitPromise: true, returnByValue: true },
                    restartedSession.sessionId,
                );
                assert.equal(
                    result.exceptionDetails,
                    undefined,
                    JSON.stringify(result.exceptionDetails),
                );
                return result.result.value;
            }
            for (let attempt = 0; attempt < 200; attempt++) {
                if (
                    await offlineEvaluate(
                        'document.body?.innerText.includes("saved.txt")',
                    )
                )
                    break;
                await new Promise((resolve) => setTimeout(resolve, 30));
            }
            assert.equal(await offlineEvaluate('location.pathname'), '/media');
            assert.equal(
                await offlineEvaluate(`(async()=>{
                const section = document.querySelector('[aria-label="Offline media player"]');
                const playlist = Array.from(section.querySelectorAll('nav button')).find(button => button.textContent.trim() === 'Saved mix');
                if (!playlist) return false;
                playlist.click();
                await new Promise(resolve => setTimeout(resolve, 0));
                section.querySelector('li button')?.click();
                for (let i = 0; i < 100; i++) {
                    const audio = section.querySelector('audio');
                    if (audio?.readyState > 0) {
                        await audio.play();
                        return audio.currentSrc.startsWith('blob:') && audio.duration === 2 && !audio.paused;
                    }
                    await new Promise(resolve => setTimeout(resolve, 20));
                }
                return false;
            })()`),
                true,
            );
            assert.equal(
                await offlineEvaluate(
                    'document.body?.innerText.includes("saved.txt") && document.body?.innerText.includes("Offline ready")',
                ),
                true,
                await offlineEvaluate(
                    'JSON.stringify({text:document.body?.innerText,url:location.href,html:document.body?.innerHTML,controller:!!navigator.serviceWorker.controller})',
                ),
            );
            assert.equal(
                await offlineEvaluate(
                    'document.body?.innerText.includes("The original is missing")',
                ),
                true,
            );
            await offlineEvaluate(
                `window.offlineFetches=0;const originalFetch=window.fetch;window.fetch=(...args)=>{window.offlineFetches++;return originalFetch(...args);}`,
            );
            await offlineEvaluate(
                `Array.from(document.querySelectorAll('[data-test=browser-copy]')).find(row=>row.innerText.includes('saved.png')).querySelector('button').click()`,
            );
            for (let attempt = 0; attempt < 200; attempt++) {
                if (
                    await offlineEvaluate(
                        'document.querySelector("[data-test=media-viewer] img")?.naturalWidth === 1',
                    )
                )
                    break;
                await new Promise((resolve) => setTimeout(resolve, 30));
            }
            assert.equal(
                await offlineEvaluate(
                    'document.querySelector("[data-test=media-viewer] img")?.naturalWidth',
                ),
                1,
            );
            assert.equal(await offlineEvaluate('offlineFetches'), 0);
            await offlineEvaluate(
                `localStorage.setItem('roxifile.browser-account','8');window.dispatchEvent(new StorageEvent('storage',{key:'roxifile.browser-account'}))`,
            );
            assert.equal(
                await offlineEvaluate(
                    'document.querySelector("[data-test=media-viewer] img") === null',
                ),
                true,
            );
            await offlineEvaluate(
                `localStorage.setItem('roxifile.browser-account','7');window.dispatchEvent(new StorageEvent('storage',{key:'roxifile.browser-account'}))`,
            );
            for (let attempt = 0; attempt < 200; attempt++) {
                if (
                    await offlineEvaluate(
                        `Array.from(document.querySelectorAll('[data-test=browser-copy]')).some(row=>row.innerText.includes('saved.txt'))`,
                    )
                )
                    break;
                await new Promise((resolve) => setTimeout(resolve, 30));
            }
            await send('Browser.setDownloadBehavior', {
                behavior: 'allow',
                downloadPath: directory,
            });
            for (const [name, selector, ready] of [
                ['saved.wav', 'audio', 'readyState >= 2'],
                ['saved.webm', 'video', 'readyState >= 2'],
            ]) {
                await offlineEvaluate(
                    `Array.from(document.querySelectorAll('[data-test=browser-copy]')).find(row=>row.innerText.includes('${name}')).querySelector('button').click()`,
                );
                for (let attempt = 0; attempt < 200; attempt++) {
                    if (
                        await offlineEvaluate(
                            `document.querySelector('${selector}')?.${ready}`,
                        )
                    )
                        break;
                    await new Promise((resolve) => setTimeout(resolve, 30));
                }
                assert.equal(
                    await offlineEvaluate(
                        `document.querySelector('${selector}')?.${ready}`,
                    ),
                    true,
                );
                assert.equal(
                    await offlineEvaluate(
                        `(async()=>{const media=document.querySelector('${selector}');media.loop=true;await media.play();return !media.paused;})()`,
                    ),
                    true,
                );
                await offlineEvaluate(
                    `document.querySelector('${selector}').pause()`,
                );
                assert.equal(
                    await offlineEvaluate(
                        `document.querySelector('${selector}').paused`,
                    ),
                    true,
                );
            }
            assert.equal(await offlineEvaluate('offlineFetches'), 0);
            await offlineEvaluate(
                `Array.from(document.querySelectorAll('[data-test=browser-copy]')).find(row=>row.innerText.includes('saved.txt')).querySelector('button').click()`,
            );
            let exported;
            for (let attempt = 0; attempt < 200; attempt++) {
                try {
                    exported = await readFile(
                        path.join(directory, 'saved.txt'),
                        'utf8',
                    );
                    break;
                } catch {
                    await new Promise((resolve) => setTimeout(resolve, 30));
                }
            }
            assert.equal(exported, 'hello');
            await offlineEvaluate(
                `Array.from(document.querySelectorAll('[data-test=browser-copy]')).find(row=>row.innerText.includes('saved.txt')).querySelectorAll('button')[1].click()`,
            );
            await offlineEvaluate(
                `Array.from(document.querySelectorAll('[data-test=browser-copy]')).find(row=>row.innerText.includes('saved.png')).querySelectorAll('button')[2].click()`,
            );
            await offlineEvaluate(
                `Array.from(document.querySelectorAll('[data-test=browser-copy]')).find(row=>row.innerText.includes('saved.wav')).querySelectorAll('button')[2].click()`,
            );
            await offlineEvaluate(
                `Array.from(document.querySelectorAll('[data-test=browser-copy]')).find(row=>row.innerText.includes('saved.webm')).querySelectorAll('button')[2].click()`,
            );
            for (let attempt = 0; attempt < 200; attempt++) {
                if (
                    await offlineEvaluate(
                        'document.body?.innerText.includes("No complete browser copies")',
                    )
                )
                    break;
                await new Promise((resolve) => setTimeout(resolve, 30));
            }
            assert.equal(
                await offlineEvaluate(
                    'document.body?.innerText.includes("No complete browser copies")',
                ),
                true,
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
