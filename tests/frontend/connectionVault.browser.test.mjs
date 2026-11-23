import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, readdir, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { once } from 'node:events';

// Uses an already installed browser when explicitly selected; adds no browser dependency.
void test(
    'vault UI recovers on a fresh page, changes passwords and exports replacement recovery files',
    {
        skip: !process.env.CHROME_BIN,
        timeout: 90000,
    },
    async () => {
        const { createServer } = await import('vite');
        const { default: vue } = await import('@vitejs/plugin-vue');
        const root = path.resolve(import.meta.dirname, '../..');
        const directory = await mkdtemp(
            path.join(tmpdir(), 'roxifile-vault-browser-'),
        );
        const requests = [];
        let envelope;
        const server = await createServer({
            configFile: false,
            root,
            plugins: [vue()],
            optimizeDeps: {
                noDiscovery: true,
                include: [
                    'vue',
                    '@inertiajs/vue3',
                    '@vueuse/core',
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
                if (
                    req.url === '/telegram/connections/19/encryption' &&
                    req.method === 'POST'
                ) {
                    let body = '';
                    for await (const chunk of req) body += chunk;
                    const data = JSON.parse(body);
                    requests.push(data);
                    res.setHeader('Content-Type', 'application/json');
                    if (
                        JSON.stringify(data.previous_envelope) !==
                        JSON.stringify(envelope)
                    ) {
                        res.statusCode = 422;
                        res.end(
                            JSON.stringify({
                                errors: {
                                    envelope: [
                                        'Vault material changed. Reload this page.',
                                    ],
                                },
                            }),
                        );
                    } else {
                        envelope = data.envelope;
                        res.end(
                            JSON.stringify({ encryption_mode: 'contents' }),
                        );
                    }
                    return;
                }
                if (req.url === '/__vault_test') {
                    res.setHeader('Content-Type', 'text/html');
                    res.end(`<html><body><div id="app"></div><script type="module">
                import { createApp, h } from '/node_modules/.vite/deps/vue.js';
                import ConnectionVault from '/resources/js/components/ConnectionVault.vue';
                import * as cryptoLib from '/resources/js/lib/contentsEncryption.ts';
                window.cryptoLib = cryptoLib;
                window.mountVault = (envelope) => createApp({ render: () => h(ConnectionVault, {
                    context: { userId: 7, connectionId: 19 }, mode: 'contents', envelope,
                    onUnlocked: () => { window.wasUnlocked = true; },
                    onLocked: () => { window.wasUnlocked = false; },
                }) }).mount('#app');
                window.ready = true;
            </script></body></html>`);
                    return;
                }
                next();
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
                    { url: `${origin}/__vault_test` },
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
            const fixture = await evaluate(`(async () => {
            const context = { userId: 7, connectionId: 19 };
            const vault = await cryptoLib.createVault('original vault password', context);
            const encrypted = await cryptoLib.encryptFile(new Blob(['existing encrypted file']), vault.key, context);
            return { envelope: vault.envelope, recoverySecret: vault.recoverySecret, bytes: Array.from(new Uint8Array(await encrypted.file.arrayBuffer())) };
        })()`);
            envelope = fixture.envelope;
            await evaluate(`mountVault(${JSON.stringify(envelope)})`);
            await fill('vault-unlock-19', 'wrong credential');
            await click('Unlock on this device');
            await waitFor(
                `document.body.innerText.includes('Could not unlock')`,
            );
            await fill('vault-unlock-19', 'original vault password');
            await fill('vault-new-password-19', 'replacement vault password');
            await click('Change vault password');
            await waitFor(
                `document.body.innerText.includes('Vault password changed')`,
            );
            assert.equal(requests.length, 1);
            const request = JSON.stringify(requests[0]);
            assert.ok(!request.includes('original vault password'));
            assert.ok(!request.includes('replacement vault password'));
            assert.ok(!request.includes(fixture.recoverySecret));
            // Reload destroys the unlocked key and credentials, as on another device.
            await open();
            await evaluate(`mountVault(${JSON.stringify(envelope)})`);
            await fill('vault-unlock-19', 'replacement vault password');
            await click('Unlock on this device');
            await waitFor('window.wasUnlocked === true');
            assert.equal(await evaluate('localStorage.length'), 0);
            await click('Lock vault and cancel local transfers');
            await fill('vault-unlock-19', 'replacement vault password');
            await click('Prepare replacement recovery key');
            await waitFor(
                `document.body.innerText.includes('Activate replacement recovery key')`,
            );
            await click('Download recovery key');
            let filename;
            for (let attempt = 0; attempt < 100; attempt++) {
                filename = (await readdir(directory)).find((name) =>
                    name.endsWith('.json'),
                );
                if (filename) break;
                await new Promise((resolve) => setTimeout(resolve, 30));
            }
            assert.ok(filename, 'browser exported a recovery file');
            const kit = JSON.parse(
                await readFile(path.join(directory, filename), 'utf8'),
            );
            await evaluate(
                `document.querySelector('input[type="checkbox"]').click()`,
            );
            await click('Activate replacement recovery key');
            await waitFor(
                `document.body.innerText.includes('Vault credentials saved')`,
            );
            assert.equal(requests.length, 2);
            assert.ok(!JSON.stringify(requests).includes(kit.recoverySecret));
            await open();
            await evaluate(`mountVault(${JSON.stringify(envelope)})`);
            const { result: input } = await send(
                'Runtime.evaluate',
                { expression: "document.getElementById('vault-import-19')" },
                sessionId,
            );
            await send(
                'DOM.setFileInputFiles',
                {
                    objectId: input.objectId,
                    files: [path.join(directory, filename)],
                },
                sessionId,
            );
            await waitFor(
                `document.getElementById('vault-unlock-19').value.length > 0`,
            );
            await click('Unlock on this device');
            await waitFor('window.wasUnlocked === true');
            const plaintext = await evaluate(`(async () => {
            const context = { userId: 7, connectionId: 19 };
            const key = await cryptoLib.unlockVault(${JSON.stringify(envelope)}, ${JSON.stringify(kit.recoverySecret)}, context, true);
            const output = await cryptoLib.decryptFile(new Blob([new Uint8Array(${JSON.stringify(fixture.bytes)})]), key, context);
            return output.file.text();
        })()`);
            assert.equal(plaintext, 'existing encrypted file');
            assert.equal(
                await evaluate(
                    "document.getElementById('vault-unlock-19').value",
                ),
                '',
            );
            await evaluate("window.dispatchEvent(new Event('pagehide'))");
            await waitFor('window.wasUnlocked === false');
        } finally {
            socket?.close();
            chrome?.kill();
            await server.close();
            if (chrome && chrome.exitCode === null) await once(chrome, 'exit');
            await rm(directory, { recursive: true, force: true });
        }
    },
);
