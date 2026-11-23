import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { once } from 'node:events';

void test(
    'media viewer displays desktop/mobile media, handles errors and releases old sources',
    { skip: !process.env.CHROME_BIN, timeout: 240000 },
    async () => {
        const { createServer } = await import('vite');
        const { default: vue } = await import('@vitejs/plugin-vue');
        const { default: tailwindcss } = await import('@tailwindcss/vite');
        const root = path.resolve(import.meta.dirname, '../..');
        const directory = await mkdtemp(path.join(tmpdir(), 'roxifile-media-'));
        const audioBytes = Buffer.alloc(44 + 8000 * 6, 128);
        audioBytes.write('RIFF', 0);
        audioBytes.writeUInt32LE(audioBytes.length - 8, 4);
        audioBytes.write('WAVEfmt ', 8);
        audioBytes.writeUInt32LE(16, 16);
        audioBytes.writeUInt16LE(1, 20);
        audioBytes.writeUInt16LE(1, 22);
        audioBytes.writeUInt32LE(8000, 24);
        audioBytes.writeUInt32LE(8000, 28);
        audioBytes.writeUInt16LE(1, 32);
        audioBytes.writeUInt16LE(8, 34);
        audioBytes.write('data', 36);
        audioBytes.writeUInt32LE(audioBytes.length - 44, 40);
        const server = await createServer({
            configFile: false,
            root,
            cacheDir: path.join(directory, 'vite-cache'),
            plugins: [
                vue(),
                tailwindcss(),
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
                    '@lucide/vue',
                    'vue-sonner',
                    '@vueuse/core',
                    'clsx',
                    'class-variance-authority',
                    'reka-ui',
                    'tailwind-merge',
                ],
            },
            resolve: { alias: { '@': path.join(root, 'resources/js') } },
            server: { host: '127.0.0.1', port: 0 },
        });
        const ordinaryFile = (id) => ({
            id,
            name: id === 3 ? 'Sound 3' : `Photo ${id}`,
            storage_name: id === 3 ? 'Sound 3' : `Photo ${id}`,
            mime_type: id === 3 ? 'audio/wav' : 'image/png',
            file_type: id === 3 ? 'audio' : 'image',
            size: 100,
            status: 'uploaded',
            description: 'Complete off-page description',
            flagged: false,
            created_at: null,
            updated_at: null,
            deleted_at: null,
            directory_id: 9,
            directory_path: 'Full/Nested/Path',
            tags: [{ id: 1, name: 'complete-tag' }],
            telegram: {
                account: null,
                connection: null,
                method: null,
                chat_id: null,
                message_id: null,
            },
            encryption_header: null,
            connection_id: null,
            metadata_protected: false,
            protected_metadata: null,
        });
        const filePage = {
            component: 'files/Index',
            url: '/files?file=019a884c-a43c-70ee-8f6c-f7f9646b2c01',
            version: 'test',
            props: {
                auth: {
                    user: { id: 7, name: 'Owner', email: 'owner@example.test' },
                },
                errors: {},
                max_upload_bytes: 7340032,
                directory: null,
                directories: [],
                breadcrumbs: [],
                operations: [],
                workerStatus: null,
                connections: [],
                vault_connections: [],
                filter_connections: [],
                selected_file: ordinaryFile(9),
                files: {
                    data: [ordinaryFile(1), ordinaryFile(2), ordinaryFile(3)],
                    current_page: 1,
                    last_page: 1,
                    total: 3,
                },
                filters: {
                    q: null,
                    file_type: null,
                    mime_type: null,
                    tag_ids: [],
                    telegram_connection_id: null,
                    created_after: null,
                    created_before: null,
                    scope: 'folder',
                    sort: 'updated_at',
                    direction: 'desc',
                    trash: false,
                },
            },
        };
        let protectedCiphertext;
        let protectedVideoCiphertext;
        let protectedAudioCiphertext;
        let tamperedVideoCiphertext;
        let protectedVideoMode = 'valid';
        let tamperedCiphertext;
        let wrongKeyCiphertext;
        let protectedCiphertextMode = 'valid';
        let protectedOperationId = 40;
        let heldPreparation;
        server.middlewares.stack.unshift({
            route: '',
            handle: async (req, res, next) => {
                if (req.url === '/__protected_ciphertext') {
                    const chunks = [];
                    for await (const chunk of req) chunks.push(chunk);
                    protectedCiphertext = Buffer.concat(chunks);
                    res.end('ok');
                    return;
                }
                if (
                    req.url === '/__protected_video_ciphertext' ||
                    req.url === '/__tampered_video_ciphertext'
                ) {
                    const chunks = [];
                    for await (const chunk of req) chunks.push(chunk);
                    if (req.url === '/__protected_video_ciphertext')
                        protectedVideoCiphertext = Buffer.concat(chunks);
                    else tamperedVideoCiphertext = Buffer.concat(chunks);
                    res.end('ok');
                    return;
                }
                if (req.url === '/__protected_audio_ciphertext') {
                    const chunks = [];
                    for await (const chunk of req) chunks.push(chunk);
                    protectedAudioCiphertext = Buffer.concat(chunks);
                    res.end('ok');
                    return;
                }
                if (req.url === '/__use_tampered_video') {
                    protectedVideoMode = 'tampered';
                    res.end('ok');
                    return;
                }
                if (
                    req.url === '/__tampered_ciphertext' ||
                    req.url === '/__wrong_key_ciphertext'
                ) {
                    const chunks = [];
                    for await (const chunk of req) chunks.push(chunk);
                    if (req.url === '/__tampered_ciphertext')
                        tamperedCiphertext = Buffer.concat(chunks);
                    else wrongKeyCiphertext = Buffer.concat(chunks);
                    res.end('ok');
                    return;
                }
                if (
                    req.url === '/__use_tampered_ciphertext' ||
                    req.url === '/__use_wrong_key_ciphertext'
                ) {
                    protectedCiphertextMode = req.url.includes('tampered')
                        ? 'tampered'
                        : 'wrong-key';
                    res.end('ok');
                    return;
                }
                if (req.url === '/files/4/prepare-download') {
                    res.setHeader('Content-Type', 'application/json');
                    res.end(
                        JSON.stringify({
                            operation_id: protectedOperationId++,
                            status: 'completed',
                        }),
                    );
                    return;
                }
                if (req.url === '/files/10/prepare-download') {
                    res.setHeader('Content-Type', 'application/json');
                    res.end(
                        JSON.stringify({
                            operation_id: protectedOperationId++,
                            status: 'completed',
                        }),
                    );
                    return;
                }
                if (
                    req.url === '/files/5/prepare-download' ||
                    req.url === '/files/9/prepare-download'
                ) {
                    res.setHeader('Content-Type', 'application/json');
                    res.end(
                        JSON.stringify({
                            operation_id: protectedOperationId++,
                            status: 'completed',
                        }),
                    );
                    return;
                }
                if (req.url.startsWith('/files/4/download')) {
                    res.setHeader('Content-Type', 'application/octet-stream');
                    res.end(
                        protectedCiphertextMode === 'tampered'
                            ? tamperedCiphertext
                            : protectedCiphertextMode === 'wrong-key'
                              ? wrongKeyCiphertext
                              : protectedCiphertext,
                    );
                    return;
                }
                if (req.url.startsWith('/files/10/download')) {
                    res.setHeader('Content-Type', 'application/octet-stream');
                    res.end(protectedAudioCiphertext);
                    return;
                }
                if (
                    req.url.startsWith('/files/5/download') ||
                    req.url.startsWith('/files/9/download')
                ) {
                    res.setHeader('Content-Type', 'application/octet-stream');
                    res.end(
                        protectedVideoMode === 'tampered'
                            ? tamperedVideoCiphertext
                            : protectedVideoCiphertext,
                    );
                    return;
                }
                if (req.url === '/files/3/prepare-download') {
                    res.setHeader('Content-Type', 'application/json');
                    res.end('{"status":"completed","operation_id":3}');
                    return;
                }
                if (req.url.startsWith('/files/3/download')) {
                    res.setHeader('Content-Type', 'audio/wav');
                    res.end(audioBytes);
                    return;
                }
                if (req.url === '/tags') {
                    res.setHeader('Content-Type', 'application/json');
                    res.end('{"tags":[]}');
                    return;
                }
                if (req.url === '/files/1/prepare-download') {
                    heldPreparation = res;
                    return;
                }
                if (req.url === '/files/2/prepare-download') {
                    res.setHeader('Content-Type', 'application/json');
                    res.end('{"status":"completed","operation_id":2}');
                    return;
                }
                if (req.url.startsWith('/files/2/download')) {
                    res.setHeader('Content-Type', 'image/png');
                    res.end(
                        Buffer.from(
                            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP6sAAAAASUVORK5CYII=',
                            'base64',
                        ),
                    );
                    return;
                }
                if (req.url === '/__files_test') {
                    res.setHeader('Content-Type', 'text/html');
                    res.end(
                        await server.transformIndexHtml(
                            req.url,
                            `<html><body><div id="app"></div><script type="module">
                    import {createApp,h} from 'vue';
                    import {createInertiaApp} from '@inertiajs/vue3';
                    import Index from '/resources/js/pages/files/Index.vue';
                    import {SidebarProvider} from '/resources/js/components/ui/sidebar/index.ts';
                    import {createVault,encryptFile} from '/resources/js/lib/contentsEncryption.ts';
                    import {setBrowserAccount} from '/resources/js/lib/browserDownloads.ts';
                    localStorage.setItem('roxifile.files.view','list');
                    setBrowserAccount(7);
                    const page=${JSON.stringify(filePage)};
                    const context={userId:7,connectionId:19};
                    const vault=await createVault('separate media vault password',context);
                    const png=Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP6sAAAAASUVORK5CYII='),c=>c.charCodeAt(0));
                    const encrypted=await encryptFile(new Blob([png],{type:'image/png'}),vault.key,context);
                    const header=new Uint8Array(await encrypted.file.slice(0,64).arrayBuffer());
                    page.props.connections=[{id:19,name:'Protected media',method:'invite',encryption_mode:'contents',vault_envelope:vault.envelope}];
                    page.props.files.data.push({id:4,name:'Photo 4',storage_name:'Photo 4',mime_type:'image/png',file_type:'image',size:encrypted.file.size,status:'uploaded',description:null,flagged:false,created_at:null,updated_at:null,deleted_at:null,directory_id:null,directory_path:'',tags:[],telegram:{account:null,connection:null,method:null,chat_id:null,message_id:null},encryption_header:btoa(String.fromCharCode(...header)),connection_id:19,metadata_protected:false,protected_metadata:null});
                    await fetch('/__protected_ciphertext',{method:'POST',body:encrypted.file});
                    const canvas=document.createElement('canvas');canvas.width=64;canvas.height=64;const drawing=canvas.getContext('2d');
                    const recording=canvas.captureStream(12);const recorder=new MediaRecorder(recording,{mimeType:'video/webm;codecs=vp8'});const chunks=[];recorder.ondataavailable=event=>{if(event.data.size)chunks.push(event.data);};const stopped=new Promise(resolve=>recorder.addEventListener('stop',resolve,{once:true}));recorder.start();for(let frame=0;frame<24;frame++){drawing.fillStyle=frame%2?'red':'blue';drawing.fillRect(0,0,64,64);await new Promise(resolve=>setTimeout(resolve,84));}recorder.stop();await stopped;recording.getTracks().forEach(track=>track.stop());
                    const videoMime='video/webm;codecs=vp8';const encryptedVideo=await encryptFile(new Blob(chunks,{type:videoMime}),vault.key,context);const videoHeader=new Uint8Array(await encryptedVideo.file.slice(0,64).arrayBuffer());const tamperedVideoBytes=new Uint8Array(await encryptedVideo.file.arrayBuffer());tamperedVideoBytes[tamperedVideoBytes.length-1]^=1;
                    page.props.files.data.push({id:5,name:'Video 5',storage_name:'Video 5',mime_type:videoMime,file_type:'video',size:encryptedVideo.file.size,status:'uploaded',description:null,flagged:false,created_at:null,updated_at:null,deleted_at:null,directory_id:null,directory_path:'',tags:[],telegram:{account:null,connection:null,method:null,chat_id:null,message_id:null},encryption_header:btoa(String.fromCharCode(...videoHeader)),connection_id:19,metadata_protected:false,protected_metadata:null});
                    page.props.files.data.push({id:9,name:'Video 9',storage_name:'Video 9',mime_type:videoMime,file_type:'video',size:33*1024*1024,status:'uploaded',description:null,flagged:false,created_at:null,updated_at:null,deleted_at:null,directory_id:null,directory_path:'',tags:[],telegram:{account:null,connection:null,method:null,chat_id:null,message_id:null},encryption_header:btoa(String.fromCharCode(...videoHeader)),connection_id:19,metadata_protected:false,protected_metadata:null});
                    window.savePickerCalls=0;Object.defineProperty(window,'showSaveFilePicker',{configurable:true,value:async()=>{window.savePickerCalls++;throw new Error('unexpected save picker');}});
                    page.props.files.data.push({id:6,name:'Unsafe 6',storage_name:'Unsafe 6',mime_type:'image/svg+xml',file_type:'image',size:encrypted.file.size,status:'uploaded',description:null,flagged:false,created_at:null,updated_at:null,deleted_at:null,directory_id:null,directory_path:'',tags:[],telegram:{account:null,connection:null,method:null,chat_id:null,message_id:null},encryption_header:btoa(String.fromCharCode(...header)),connection_id:19,metadata_protected:false,protected_metadata:null});
                    const tamperedBytes=new Uint8Array(await encrypted.file.arrayBuffer());tamperedBytes[tamperedBytes.length-1]^=1;
                    const foreignVault=await createVault('different protected media password',context);const wrongKeyFile=await encryptFile(new Blob([png],{type:'image/png'}),foreignVault.key,context);const wrongKeyBytes=new Uint8Array(await wrongKeyFile.file.arrayBuffer());wrongKeyBytes.set(header,0);
                    await fetch('/__tampered_ciphertext',{method:'POST',body:new Blob([tamperedBytes])});
                    await fetch('/__wrong_key_ciphertext',{method:'POST',body:new Blob([wrongKeyBytes])});
                    await fetch('/__protected_video_ciphertext',{method:'POST',body:encryptedVideo.file});
                    await fetch('/__tampered_video_ciphertext',{method:'POST',body:new Blob([tamperedVideoBytes])});
                    const audioContext=new AudioContext();const destination=audioContext.createMediaStreamDestination();const oscillator=audioContext.createOscillator();oscillator.connect(destination);oscillator.start();const audioRecorder=new MediaRecorder(destination.stream,{mimeType:'audio/webm;codecs=opus'});const audioChunks=[];audioRecorder.ondataavailable=event=>{if(event.data.size)audioChunks.push(event.data);};const audioStopped=new Promise(resolve=>audioRecorder.addEventListener('stop',resolve,{once:true}));audioRecorder.start();await new Promise(resolve=>setTimeout(resolve,1000));audioRecorder.stop();await audioStopped;oscillator.stop();destination.stream.getTracks().forEach(track=>track.stop());await audioContext.close();const encryptedAudio=await encryptFile(new Blob(audioChunks,{type:'audio/webm'}),vault.key,context);const audioHeader=new Uint8Array(await encryptedAudio.file.slice(0,64).arrayBuffer());page.props.files.data.push({id:10,name:'Sound 10',storage_name:'Sound 10',mime_type:'audio/webm;codecs=opus',file_type:'audio',size:encryptedAudio.file.size,status:'uploaded',description:null,flagged:false,created_at:null,updated_at:null,deleted_at:null,directory_id:null,directory_path:'',tags:[],telegram:{account:null,connection:null,method:null,chat_id:null,message_id:null},encryption_header:btoa(String.fromCharCode(...audioHeader)),connection_id:19,metadata_protected:false,protected_metadata:null});await fetch('/__protected_audio_ciphertext',{method:'POST',body:encryptedAudio.file});
                    await createInertiaApp({page,resolve:()=>Index,setup({el,App,props,plugin}){createApp({render:()=>h(SidebarProvider,()=>h(App,props))}).use(plugin).mount(el);}});
                    window.ready=true;
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
                if (req.url === '/__crossfade_test') {
                    res.setHeader('Content-Type', 'text/html');
                    res.end(
                        await server.transformIndexHtml(
                            req.url,
                            `<html><body><div id="app"></div><script type="module">
import { createApp, h } from 'vue';
import MediaPlayback from '/resources/js/components/MediaPlayback.vue';
import { listBrowserCopies, saveBrowserCopy, setBrowserAccount } from '/resources/js/lib/browserDownloads.ts';
setBrowserAccount(7);
const track = (id, name, encrypted = false) => ({ id, name, encrypted, mime_type: 'audio/wav' });
const tracks = [track(3, 'Telegram track'), track(80, 'Browser download'), track(81, 'Locked audio', true)];
const response = await fetch('/files/3/download');
const bytes = await response.arrayBuffer();
const file = { ...tracks[1], size: bytes.byteLength, directory_path: '', description: null, tags: [] };
await saveBrowserCopy(7, file, new Response(bytes), new AbortController().signal, () => {});
const copies = await listBrowserCopies(7);
window.played = [];
window.player = createApp(MediaPlayback, {
    account: 7, tracks, copies, offline: false,
    remoteUrl: async id => '/files/' + id + '/download',
    remoteEncrypted: async id => ({ copy: { ...file, id, encryption_header: 'locked' }, load: async () => { throw new Error('Ciphertext loaded before unlock'); } }),
    onPlayed: id => window.played.push(id),
}).mount('#app');
</script></body></html>`,
                        ),
                    );
                    return;
                }
                if (req.url !== '/__media_test') return next();
                res.setHeader('Content-Type', 'text/html');
                res.end(
                    await server.transformIndexHtml(
                        req.url,
                        `<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="app"></div><script type="module">
 import {createApp,h,reactive} from 'vue';
 import '/resources/css/app.css';
 import MediaViewer from '/resources/js/components/MediaViewer.vue';
 window.state=reactive({open:true,file:{id:1,name:'Photo',mime_type:'image/png'},url:'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP6sAAAAASUVORK5CYII=',error:''});
 createApp({render:()=>h(MediaViewer,{...window.state,'onUpdate:open':value=>state.open=value,onDownload:()=>window.downloaded=true,onRetry:()=>window.retried=true})}).mount('#app');
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
                    { url: `${origin}/__media_test` },
                    sessionId,
                );
                await waitFor('window.ready === true');
            }
            async function click(label) {
                await evaluate(
                    `Array.from(document.querySelectorAll('button')).find(b => b.textContent.trim() === ${JSON.stringify(label)}).click()`,
                );
            }
            async function fill(id, value) {
                await evaluate(
                    `{ const input = document.getElementById(${JSON.stringify(id)}); input.value = ${JSON.stringify(value)}; input.dispatchEvent(new Event('input', { bubbles: true })); }`,
                );
            }

            await send(
                'Page.navigate',
                { url: `${origin}/__crossfade_test` },
                sessionId,
            );
            await waitFor('!!window.player');
            await evaluate(`
                const setting = document.querySelector('select');
                setting.value = '2';
                setting.dispatchEvent(new Event('change', { bubbles: true }));
                player.play(3);
            `);
            await waitFor('document.querySelectorAll("audio").length === 2');
            await waitFor(
                'document.querySelectorAll("audio")[1].readyState >= 3',
            );
            await evaluate(`
                window.outgoing = document.querySelectorAll('audio')[0];
                window.incoming = document.querySelectorAll('audio')[1];
                outgoing.currentTime = outgoing.duration - 1.5;
            `);
            await waitFor(
                '!outgoing.paused && !incoming.paused && incoming.volume > 0 && outgoing.volume < 1',
            );
            await waitFor(
                'document.querySelectorAll("audio").length === 1 && document.querySelector("audio") === incoming',
            );
            assert.deepEqual(await evaluate('played'), [3, 80]);
            assert.equal(await evaluate('incoming.volume'), 1);
            await evaluate('incoming.currentTime = incoming.duration - 0.4');
            await waitFor(
                `!!document.querySelector('input[aria-label="Vault password"]')`,
            );
            assert.deepEqual(await evaluate('played'), [3, 80]);

            await open();
            await send(
                'Emulation.setDeviceMetricsOverride',
                {
                    width: 1280,
                    height: 900,
                    deviceScaleFactor: 1,
                    mobile: false,
                },
                sessionId,
            );
            await waitFor('document.querySelector("img")?.naturalWidth === 1');
            await waitFor(
                'document.querySelector("[data-test=media-viewer]")?.getBoundingClientRect().right === 1280',
            );
            assert.equal(
                await evaluate(
                    'document.querySelector("[data-test=media-viewer]").getBoundingClientRect().width < 1280',
                ),
                true,
            );
            await send(
                'Emulation.setDeviceMetricsOverride',
                { width: 390, height: 844, deviceScaleFactor: 1, mobile: true },
                sessionId,
            );
            await waitFor(
                'Math.abs(document.querySelector("[data-test=media-viewer]").getBoundingClientRect().width - innerWidth) < 1',
            );
            await evaluate(
                `state.file={id:2,name:'Sound',mime_type:'audio/wav'}; const bytes=new Uint8Array(44+16000); const view=new DataView(bytes.buffer); const text=(p,s)=>[...s].forEach((v,i)=>view.setUint8(p+i,v.charCodeAt(0))); text(0,'RIFF');view.setUint32(4,bytes.length-8,true);text(8,'WAVE');text(12,'fmt ');view.setUint32(16,16,true);view.setUint16(20,1,true);view.setUint16(22,1,true);view.setUint32(24,8000,true);view.setUint32(28,8000,true);view.setUint16(32,1,true);view.setUint16(34,8,true);text(36,'data');view.setUint32(40,16000,true);bytes.fill(128,44);state.url=URL.createObjectURL(new Blob([bytes],{type:'audio/wav'}));`,
            );
            await waitFor('document.querySelector("audio")?.readyState >= 2');
            assert.equal(
                await evaluate(
                    `!!document.querySelector('canvas[aria-label="Live audio waveform"]')`,
                ),
                true,
            );
            await evaluate(`
                window.oldMedia = document.querySelector('audio');
                window.waveform = document.querySelector('canvas[aria-label="Live audio waveform"]');
                window.inlineWaveformHeight = waveform.getBoundingClientRect().height;
                document.querySelector('button[aria-label="Fullscreen"]').click();
            `);
            await waitFor('document.fullscreenElement?.contains(waveform)');
            await waitFor(
                'waveform.getBoundingClientRect().height > inlineWaveformHeight',
            );
            await evaluate(
                `document.fullscreenElement.querySelector('button[aria-label="Play"]').click()`,
            );
            await waitFor('!oldMedia.paused');
            await waitFor(
                'document.fullscreenElement.querySelectorAll("fieldset input[type=range]").length === 5',
            );
            await evaluate(`
                const fields = document.fullscreenElement.querySelector('fieldset');
                const preset = fields.querySelector('select');
                preset.value = 'Bass boost';
                preset.dispatchEvent(new Event('change', { bubbles: true }));
            `);
            await waitFor(
                'document.fullscreenElement.querySelector("fieldset input[type=range]").value === "6"',
            );
            await evaluate(`
                const band = document.fullscreenElement.querySelector('fieldset input[type=range]');
                band.value = '3';
                band.dispatchEvent(new Event('input', { bubbles: true }));
            `);
            await waitFor(
                'document.fullscreenElement.querySelector("fieldset select").value === "Custom"',
            );
            await evaluate(
                `document.fullscreenElement.querySelector('fieldset input[type=checkbox]').click()`,
            );
            await waitFor(
                'document.fullscreenElement.querySelector("fieldset input[type=range]").disabled',
            );
            await evaluate(
                `document.fullscreenElement.querySelector('button[aria-label="Exit fullscreen"]').click()`,
            );
            await waitFor('!document.fullscreenElement');
            assert.equal(await evaluate('oldMedia.paused'), false);
            await evaluate('oldMedia.pause()');
            assert.equal(await evaluate('oldMedia.paused'), true);
            await send(
                'Emulation.setDeviceMetricsOverride',
                {
                    width: 1280,
                    height: 900,
                    deviceScaleFactor: 1,
                    mobile: false,
                },
                sessionId,
            );
            await evaluate('void oldMedia.play(); true');
            await waitFor('!oldMedia.paused');
            await evaluate('oldMedia.pause()');
            assert.equal(await evaluate('oldMedia.paused'), true);
            await send(
                'Emulation.setDeviceMetricsOverride',
                { width: 390, height: 844, deviceScaleFactor: 1, mobile: true },
                sessionId,
            );
            await evaluate('void oldMedia.play(); true');
            await evaluate(
                `(async () => { const canvas=document.createElement('canvas'); canvas.width=16;canvas.height=16; const stream=canvas.captureStream(10);const chunks=[];const recorder=new MediaRecorder(stream,{mimeType:'video/webm;codecs=vp8'});const finished=new Promise(resolve=>recorder.onstop=resolve);recorder.ondataavailable=event=>chunks.push(event.data);recorder.start();const context=canvas.getContext('2d');for(let i=0;i<5;i++){context.fillStyle=i%2?'red':'blue';context.fillRect(0,0,16,16);await new Promise(resolve=>setTimeout(resolve,100));}recorder.stop();await finished;stream.getTracks().forEach(track=>track.stop());state.file={id:5,name:'Video',mime_type:'video/webm'};state.url=URL.createObjectURL(new Blob(chunks,{type:'video/webm'}));})()`,
            );
            await waitFor('oldMedia.paused && !oldMedia.hasAttribute("src")');
            await waitFor('document.querySelector("video")?.readyState >= 2');
            assert.equal(
                await evaluate(
                    `!!document.querySelector('button[aria-label="Fullscreen"]')`,
                ),
                true,
            );
            await evaluate(
                'window.oldMedia=document.querySelector("video"); void oldMedia.play(); true',
            );
            await waitFor('!oldMedia.paused');
            await evaluate('oldMedia.pause()');
            assert.equal(await evaluate('oldMedia.paused'), true);
            await send(
                'Emulation.setDeviceMetricsOverride',
                {
                    width: 1280,
                    height: 900,
                    deviceScaleFactor: 1,
                    mobile: false,
                },
                sessionId,
            );
            await evaluate('void oldMedia.play(); true');
            await waitFor('!oldMedia.paused');
            await evaluate('oldMedia.pause()');
            assert.equal(await evaluate('oldMedia.paused'), true);
            await send(
                'Emulation.setDeviceMetricsOverride',
                { width: 390, height: 844, deviceScaleFactor: 1, mobile: true },
                sessionId,
            );
            await evaluate('void oldMedia.play(); state.open=false');
            await waitFor('oldMedia.paused && !oldMedia.hasAttribute("src")');
            await evaluate(
                `state.open=true;state.file={id:3,name:'Untrusted',mime_type:'image/svg+xml'};state.url='data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" onload="window.unsafe=true"/>'`,
            );
            await waitFor('document.body.innerText.includes("unavailable")');
            assert.equal(
                await evaluate(
                    '!!document.querySelector("iframe,object,embed,img,video,audio")',
                ),
                false,
            );
            await click('Download');
            assert.equal(await evaluate('window.downloaded'), true);
            await evaluate(
                `state.file={id:4,name:'Broken',mime_type:'video/mp4'};state.url='data:video/mp4,broken'`,
            );
            await waitFor(
                'document.body.innerText.includes("Playback is unavailable")',
            );
            await click('Prepare again');
            assert.equal(await evaluate('window.retried'), true);
            await evaluate(`state.url='';state.error=''`);
            await waitFor(
                'document.body.innerText.includes("Preparing preview")',
            );
            await send(
                'Page.navigate',
                { url: `${origin}/__files_test` },
                sessionId,
            );
            await waitFor(
                'window.ready === true && document.getElementById("description") !== null',
            );
            assert.equal(
                await evaluate('document.getElementById("description").value'),
                'Complete off-page description',
            );
            assert.equal(
                await evaluate(
                    'document.body.innerText.includes("complete-tag") && document.body.innerText.includes("Full/Nested/Path")',
                ),
                true,
            );
            assert.equal(
                await evaluate(
                    `document.querySelectorAll('[data-test="media-viewer"]').length === 1 && document.querySelector('[data-test="media-viewer"] [data-test="file-details"] #description') !== null`,
                ),
                true,
                'Deep links show editable details inside the preview panel',
            );
            await click('Close');
            async function previewRow(id) {
                const label =
                    id === 3 || id === 10
                        ? 'Sound'
                        : id === 5 || id === 9
                          ? 'Video'
                          : id === 6
                            ? 'Unsafe'
                            : 'Photo';
                await evaluate(
                    `Array.from(document.querySelectorAll('[data-test=file-row]')).find(row=>row.innerText.includes('${label} ${id}')).querySelector('[data-test=entry-menu-button]').click()`,
                );
                await waitFor(
                    `Array.from(document.querySelectorAll('[role=menuitem]')).some(item=>item.textContent.includes('Details & preview'))`,
                );
                await evaluate(
                    `Array.from(document.querySelectorAll('[role=menuitem]')).find(item=>item.textContent.includes('Details & preview')).click()`,
                );
                await waitFor(
                    'document.querySelector("[data-test=media-viewer]") !== null',
                );
            }
            await evaluate(
                `{ const send=XMLHttpRequest.prototype.send; XMLHttpRequest.prototype.send=function(...args){this.addEventListener('loadend',()=>{if(this.status===422)window.failedPreparationReceived=true;});return send.apply(this,args);}; }`,
            );
            await previewRow(1);
            for (let attempt = 0; attempt < 200 && !heldPreparation; attempt++)
                await new Promise((resolve) => setTimeout(resolve, 30));
            assert.ok(heldPreparation);
            await click('Close');
            await previewRow(2);
            await waitFor(
                'document.querySelector("[data-test=media-viewer] img")?.naturalWidth === 1',
            );
            heldPreparation.statusCode = 422;
            heldPreparation.setHeader('Content-Type', 'application/json');
            heldPreparation.end(
                '{"message":"Failed earlier preparation","errors":{"file":["Failed earlier preparation"]}}',
            );
            await waitFor('window.failedPreparationReceived === true');
            assert.equal(
                await evaluate(
                    'document.querySelector("[data-test=media-viewer] [role=alert]") === null',
                ),
                true,
            );
            assert.equal(
                await evaluate(
                    'document.querySelector("[data-test=media-viewer] img")?.naturalWidth',
                ),
                1,
            );

            await previewRow(4);
            assert.equal(
                await evaluate(
                    `document.querySelector('[data-test="media-viewer"] [data-test="file-details"]') !== null`,
                ),
                true,
                'The file menu opens preview and details together',
            );
            await waitFor(
                'document.querySelector("[data-test=media-viewer] [role=alert]")?.textContent.includes("Unlock this file")',
            );
            await click('Close');
            await fill('vault-unlock-19', 'separate media vault password');
            await click('Unlock on this device');
            await waitFor(
                'document.body.innerText.includes("Unlocked on this page")',
            );
            await previewRow(4);
            await waitFor(
                'document.querySelector("[data-test=media-viewer] img")?.naturalWidth === 1',
            );
            assert.ok(protectedCiphertext);
            assert.equal(
                protectedCiphertext.includes(
                    Buffer.from(
                        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP6sAAAAASUVORK5CYII=',
                        'base64',
                    ),
                ),
                false,
            );
            await click('Close');
            await previewRow(5);
            await waitFor(
                'document.querySelector("[data-test=media-viewer] video")?.readyState >= 2',
            );
            assert.ok(protectedVideoCiphertext);
            await evaluate(
                'window.protectedVideo=document.querySelector("[data-test=media-viewer] video"); void protectedVideo.play(); true',
            );
            await waitFor('protectedVideo && !protectedVideo.paused');
            await click('Lock vault and cancel local transfers');
            await waitFor(
                'protectedVideo.paused && !protectedVideo.hasAttribute("src")',
            );
            await fill('vault-unlock-19', 'separate media vault password');
            await click('Unlock on this device');
            await waitFor(
                'document.body.innerText.includes("Unlocked on this page")',
            );
            await evaluate("fetch('/__use_tampered_video',{method:'POST'})");
            await previewRow(5);
            await waitFor(
                'document.querySelector("[data-test=media-viewer] [role=alert]")?.textContent.includes("The vault key may be wrong or the encrypted file may be damaged")',
            );
            await click('Close');
            await previewRow(10);
            await waitFor(
                'document.querySelector("[data-test=media-viewer] audio")?.readyState >= 2',
            );
            assert.ok(protectedAudioCiphertext);
            await click('Close');
            await previewRow(9);
            await waitFor(
                'document.querySelector("[data-test=media-viewer] [role=alert]")?.textContent.includes("incomplete")',
            );
            assert.equal(await evaluate('window.savePickerCalls'), 0);
            await click('Close');
            await previewRow(6);
            await waitFor(
                'document.querySelector("[data-test=media-viewer] [role=alert]")?.textContent.includes("Protected preview is unavailable")',
            );
            await click('Close');
            await evaluate(
                "fetch('/__use_tampered_ciphertext',{method:'POST'})",
            );
            await previewRow(4);
            await waitFor(
                'document.querySelector("[data-test=media-viewer] [role=alert]")?.textContent.includes("The vault key may be wrong or the encrypted file may be damaged")',
            );
            await click('Close');
            await evaluate(
                "fetch('/__use_wrong_key_ciphertext',{method:'POST'})",
            );
            await previewRow(4);
            await waitFor(
                'document.querySelector("[data-test=media-viewer] [role=alert]")?.textContent.includes("The vault key may be wrong or the encrypted file may be damaged")',
            );
            await click('Close');

            await previewRow(3);
            await waitFor('document.querySelector("audio")?.readyState >= 2');
            await evaluate(
                'window.logoutAudio=document.querySelector("audio"); void logoutAudio.play(); true',
            );
            await waitFor('logoutAudio && !logoutAudio.paused');
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
                'logoutAudio.paused && !logoutAudio.hasAttribute("src")',
            );
            assert.equal(
                await evaluate(
                    "localStorage.getItem('roxifile.browser-retain.7') === 'yes' && localStorage.getItem('roxifile.browser-account') === '7'",
                ),
                true,
            );
        } finally {
            socket?.close();
            chrome?.kill();
            await server.close();
            if (chrome && chrome.exitCode === null) await once(chrome, 'exit');
            await rm(directory, { recursive: true, force: true });
        }
    },
);
