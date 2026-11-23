import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { once } from 'node:events';

void test(
    'Chromium decrypts authenticated media chunks directly into MediaSource',
    { skip: !process.env.CHROME_BIN, timeout: 180000 },
    async () => {
        const { createServer } = await import('vite');
        const root = path.resolve(import.meta.dirname, '../..');
        const directory = await mkdtemp(
            path.join(tmpdir(), 'roxifile-encrypted-media-'),
        );
        const server = await createServer({
            configFile: false,
            root,
            cacheDir: path.join(directory, 'vite-cache'),
            resolve: { alias: { '@': path.join(root, 'resources/js') } },
            server: { host: '127.0.0.1', port: 0 },
        });
        server.middlewares.stack.unshift({
            route: '',
            handle: async (req, res, next) => {
                if (req.url !== '/__encrypted_media_test') return next();
                res.setHeader('Content-Type', 'text/html');
                res.end(
                    await server.transformIndexHtml(
                        req.url,
                        `<html><body><video controls></video><script type="module">
                        import { encryptFile, decryptFileStream } from '/resources/js/lib/contentsEncryption.ts';
                        window.runEncryptedMediaTest = async () => {
                            const mime = 'video/webm;codecs=vp8';
                            if (!MediaSource.isTypeSupported(mime)) {
                                return { supported: false, mime };
                            }
                            const canvas = document.createElement('canvas');
                            canvas.width = 320;
                            canvas.height = 240;
                            const context2d = canvas.getContext('2d');
                            const pixels = context2d.createImageData(320, 240);
                            let random = 0x12345678;
                            const chunks = [];
                            const recordingStream = canvas.captureStream(24);
                            const recorder = new MediaRecorder(recordingStream, {
                                mimeType: mime,
                                videoBitsPerSecond: 12000000,
                            });
                            recorder.ondataavailable = event => {
                                if (event.data.size) chunks.push(event.data);
                            };
                            const stopped = new Promise(resolve => recorder.addEventListener('stop', resolve, { once: true }));
                            recorder.start();
                            for (let frame = 0; frame < 96; frame++) {
                                for (let index = 0; index < pixels.data.length; index += 4) {
                                    random ^= random << 13;
                                    random ^= random >>> 17;
                                    random ^= random << 5;
                                    pixels.data[index] = random & 255;
                                    pixels.data[index + 1] = (random >>> 8) & 255;
                                    pixels.data[index + 2] = (random >>> 16) & 255;
                                    pixels.data[index + 3] = 255;
                                }
                                context2d.putImageData(pixels, 0, 0);
                                await new Promise(resolve => setTimeout(resolve, 42));
                            }
                            recorder.stop();
                            await stopped;
                            recordingStream.getTracks().forEach(track => track.stop());
                            const plaintext = new Blob(chunks, { type: mime });
                            if (plaintext.size <= 1048576) {
                                throw new Error('The browser fixture did not exceed one authenticated chunk.');
                            }
                            const identity = { userId: 7, connectionId: 19 };
                            const rawKey = crypto.getRandomValues(new Uint8Array(32));
                            const key = await crypto.subtle.importKey('raw', rawKey, 'HKDF', false, ['deriveKey']);
                            const encrypted = await encryptFile(plaintext, key, identity);
                            const header = new Uint8Array(await encrypted.file.slice(0, 64).arrayBuffer());
                            const expectedHeader = btoa(String.fromCharCode(...header));
                            const stream = await decryptFileStream(encrypted.file, key, identity, undefined, expectedHeader);
                            const mediaSource = new MediaSource();
                            const objectUrl = URL.createObjectURL(mediaSource);
                            const video = document.querySelector('video');
                            video.src = objectUrl;
                            await new Promise((resolve, reject) => {
                                mediaSource.addEventListener('sourceopen', resolve, { once: true });
                                mediaSource.addEventListener('sourceclose', () => reject(new Error('MediaSource closed before opening.')), { once: true });
                            });
                            const sourceBuffer = mediaSource.addSourceBuffer(mime);
                            const reader = stream.getReader();
                            let decryptedBytes = 0;
                            let decryptedChunks = 0;
                            let largestChunk = 0;
                            const append = bytes => new Promise((resolve, reject) => {
                                const onUpdateEnd = () => {
                                    sourceBuffer.removeEventListener('error', onError);
                                    bytes.fill(0);
                                    resolve();
                                };
                                const onError = () => reject(new Error('MediaSource rejected decrypted media.'));
                                sourceBuffer.addEventListener('updateend', onUpdateEnd, { once: true });
                                sourceBuffer.addEventListener('error', onError, { once: true });
                                sourceBuffer.appendBuffer(bytes);
                            });
                            while (true) {
                                const { done, value } = await reader.read();
                                if (done) break;
                                decryptedChunks++;
                                decryptedBytes += value.byteLength;
                                largestChunk = Math.max(largestChunk, value.byteLength);
                                await append(value);
                            }
                            mediaSource.endOfStream();
                            if (video.readyState < 1) {
                                await Promise.race([
                                    new Promise(resolve => video.addEventListener('loadedmetadata', resolve, { once: true })),
                                    new Promise((_, reject) => setTimeout(() => reject(new Error('MediaSource did not expose WebM metadata.')), 10000)),
                                ]);
                            }
                            await video.play();
                            video.pause();
                            const result = {
                                supported: true,
                                mime,
                                plaintextBytes: plaintext.size,
                                ciphertextBytes: encrypted.file.size,
                                decryptedBytes,
                                decryptedChunks,
                                largestChunk,
                                videoWidth: video.videoWidth,
                                videoHeight: video.videoHeight,
                                mediaSourceEnded: mediaSource.readyState === 'ended',
                                sourceIsMediaSource: video.src === objectUrl,
                                streamedFileRemainedCiphertextUntilDispose: encrypted.file.size > plaintext.size,
                            };
                            video.pause();
                            video.removeAttribute('src');
                            video.load();
                            URL.revokeObjectURL(objectUrl);
                            await encrypted.dispose();
                            rawKey.fill(0);
                            return result;
                        };
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
                const callback = pending.get(response.id);
                if (!callback) return;
                pending.delete(response.id);
                if (response.error) {
                    callback.reject(new Error(response.error.message));
                } else {
                    callback.resolve(response.result);
                }
            });
            function send(method, params = {}, sessionId) {
                const id = ++sequence;
                return new Promise((resolve, reject) => {
                    const timeout = setTimeout(() => {
                        pending.delete(id);
                        reject(new Error(`CDP timeout: ${method}`));
                    }, 30000);
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
            await send('Page.enable', {}, sessionId);
            await send(
                'Page.navigate',
                { url: `${origin}/__encrypted_media_test` },
                sessionId,
            );
            for (let attempt = 0; attempt < 200; attempt++) {
                const ready = await send(
                    'Runtime.evaluate',
                    {
                        expression:
                            'typeof window.runEncryptedMediaTest === "function"',
                        returnByValue: true,
                    },
                    sessionId,
                );
                if (ready.result.value) break;
                if (attempt === 199) {
                    assert.fail('Browser harness page did not finish loading.');
                }
                await new Promise((resolve) => setTimeout(resolve, 25));
            }
            const result = await send(
                'Runtime.evaluate',
                {
                    expression: 'runEncryptedMediaTest()',
                    awaitPromise: true,
                    returnByValue: true,
                },
                sessionId,
            );
            assert.equal(
                result.exceptionDetails,
                undefined,
                JSON.stringify(result.exceptionDetails),
            );
            const value = result.result.value;
            assert.equal(value.supported, true, JSON.stringify(value));
            assert.ok(value.plaintextBytes > 1048576, JSON.stringify(value));
            assert.equal(value.decryptedBytes, value.plaintextBytes);
            assert.ok(value.decryptedChunks > 1, JSON.stringify(value));
            assert.ok(value.largestChunk <= 1048576, JSON.stringify(value));
            assert.ok(value.videoWidth > 0, JSON.stringify(value));
            assert.ok(value.videoHeight > 0, JSON.stringify(value));
            assert.equal(value.mediaSourceEnded, true);
            assert.equal(value.sourceIsMediaSource, true);
            assert.equal(
                value.streamedFileRemainedCiphertextUntilDispose,
                true,
            );
        } catch (error) {
            process.stderr.write(`${error.stack ?? error}\n`);
            throw error;
        } finally {
            socket?.close();
            chrome?.kill();
            await server.close();
            if (chrome && chrome.exitCode === null) await once(chrome, 'exit');
            await rm(directory, { recursive: true, force: true });
        }
    },
);
