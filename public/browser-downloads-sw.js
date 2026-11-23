const cacheName = 'roxifile-offline-v1';
// Bump this revision when the offline shell changes to refresh its cached entry point.
const shell = '/build/resources/offline.html';
const shellRevision = 'media-shortcuts';
self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const response = await fetch(`${shell}?revision=${shellRevision}`, {
                cache: 'no-store',
                credentials: 'omit',
            });
            if (!response.ok)
                throw new Error('Offline application has not been built.');
            const html = await response.clone().text();
            const assets = [...html.matchAll(/(?:src|href)="([^\"]+)"/g)]
                .map(
                    (match) =>
                        new URL(match[1], new URL(shell, self.location.origin)),
                )
                .filter(
                    (url) =>
                        url.origin === self.location.origin &&
                        url.pathname.startsWith('/build/assets/'),
                );
            const cache = await caches.open(cacheName);
            await Promise.all(
                assets.map(async (url) => {
                    const asset = await fetch(url, { credentials: 'omit' });
                    if (!asset.ok)
                        throw new Error(
                            'Offline application assets are unavailable.',
                        );
                    await cache.put(url, asset);
                }),
            );
            await cache.put(shell, response);
            await self.skipWaiting();
        })(),
    );
});
self.addEventListener('activate', (event) =>
    event.waitUntil(self.clients.claim()),
);
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'GET' || url.origin !== self.location.origin)
        return;
    if (event.request.mode === 'navigate' && url.pathname === '/media') {
        event.respondWith(
            fetch(event.request).catch(
                async () =>
                    (await (await caches.open(cacheName)).match(shell)) ??
                    Response.error(),
            ),
        );
        return;
    }
    if (url.pathname === shell || url.pathname.startsWith('/build/assets/')) {
        event.respondWith(
            caches
                .open(cacheName)
                .then(
                    async (cache) =>
                        (await cache.match(event.request)) ??
                        fetch(event.request),
                ),
        );
    }
});
