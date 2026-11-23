import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

void test('online media streams through HTTP Range and falls back to prepared playback on media errors', () => {
    const page = readFileSync(
        new URL('../../resources/js/pages/media/Index.vue', import.meta.url),
        'utf8',
    );
    const player = readFileSync(
        new URL('../../resources/js/components/MediaPlayback.vue', import.meta.url),
        'utf8',
    );

    assert.match(page, /tracksById\.value\.get\(id\)\?\.streamable/);
    assert.match(page, /:remote-url="remotePlaybackUrl"/);
    assert.match(page, /streamDownload\(\{ file: id \}\)\.url/);
    assert.match(page, /:fallback-remote-url=/);
    assert.match(page, /preparedDownloadUrl\(id, signal, true\)/);
    assert.match(player, /streamingSource\.value = track\.streamable === true && !track\.encrypted/);
    assert.match(player, /@error="item\.active && fallbackToPreparedDownload\(item\.url\)"/);
    assert.match(player, /streamingSource\.value = false;/);
    assert.match(player, /resumeAfterFallback = media\.value\?\.currentTime \?\? 0/);
    assert.match(player, /@loadedmetadata="item\.active && restorePlaybackPosition\(\)"/);
});

void test('media library keeps one shared player dock across library and playlist views', () => {
    const page = readFileSync(
        new URL('../../resources/js/pages/media/Index.vue', import.meta.url),
        'utf8',
    );
    assert.match(page, /<aside[\s\S]*aria-label="Playlists"[\s\S]*<\/aside>/);
    assert.match(
        page,
        /sticky bottom-0[^"]*"\s*>\s*<MediaPlayback[^>]*\bdocked\b/,
    );
    assert.equal(page.match(/<MediaPlayback\b/g)?.length, 1);
});

void test('bulk selection follows displayed order and excludes hidden tracks', async () => {
    const { orderMediaTracks, selectedMediaIds } =
        await import('../../resources/js/lib/mediaTrackList.ts');
    const tracks = [
        { id: 4, name: 'First', protected: false, rating: 3 },
        { id: 2, name: 'Song 10', protected: false, rating: 8 },
        { id: 1, name: 'Song 2', protected: false, rating: null },
        { id: 3, name: 'A private name', protected: true, rating: 6 },
    ];
    const selection = new Set([2, 5, 1, 3]);

    assert.deepEqual(selectedMediaIds(tracks, selection), [2, 1, 3]);
    assert.deepEqual(
        selectedMediaIds(orderMediaTracks(tracks, 'name-asc'), selection),
        [3, 1, 2],
    );
    assert.deepEqual(
        selectedMediaIds(orderMediaTracks(tracks, 'name-desc'), selection),
        [2, 1, 3],
    );
    assert.deepEqual(
        selectedMediaIds(orderMediaTracks(tracks, 'rating'), selection),
        [2, 3, 1],
    );
});

void test('moving a track exchanges its position with the next available track', async () => {
    const { movePlaylistTrack } =
        await import('../../resources/js/lib/mediaTrackList.ts');
    const saved = [1, 9, 2, 3];
    const displayed = [1, 2, 3];

    assert.deepEqual(movePlaylistTrack(saved, displayed, 2, -1), [2, 9, 1, 3]);
    assert.deepEqual(movePlaylistTrack(saved, displayed, 2, 1), [1, 9, 3, 2]);
    assert.equal(movePlaylistTrack(saved, displayed, 1, -1), undefined);
    assert.equal(movePlaylistTrack(saved, displayed, 3, 1), undefined);
    assert.deepEqual(saved, [1, 9, 2, 3]);
});

void test('playlist navigation nests moved folders and keeps root playlists visible', async () => {
    const { playlistNavigation } =
        await import('../../resources/js/lib/playlistNavigation.ts');
    const folders = [
        { id: 3, name: 'Nested', parent_id: 2 },
        { id: 1, name: 'Original parent', parent_id: null },
        { id: 2, name: 'New parent', parent_id: null },
    ];
    const playlists = [
        { id: 7, name: 'Root mix', file_ids: [5] },
        { id: 8, name: 'Nested mix', file_ids: [9, 4], folder_id: 3 },
    ];

    assert.deepEqual(
        playlistNavigation(folders, playlists).map(({ kind, id, depth }) => [
            kind,
            id,
            depth,
        ]),
        [
            ['folder', 1, 0],
            ['folder', 2, 0],
            ['folder', 3, 1],
            ['playlist', 8, 2],
            ['playlist', 7, 0],
        ],
    );
});

void test('moving a playlist retains the track order in the offline catalog', async (context) => {
    const { cacheMediaPlaylists, cachedMediaPlaylists } =
        await import('../../resources/js/lib/browserDownloads.ts');
    const rows = new Map([['roxifile.browser-account', '7']]);
    Object.defineProperty(globalThis, 'localStorage', {
        configurable: true,
        value: {
            getItem: (key) => rows.get(key) ?? null,
            setItem: (key, value) => rows.set(key, value),
        },
    });
    context.after(() => {
        Reflect.deleteProperty(globalThis, 'localStorage');
    });

    const playlist = { id: 1, name: 'Mix', file_ids: [9, 4] };
    cacheMediaPlaylists(7, [playlist]);
    cacheMediaPlaylists(7, [{ ...playlist, folder_id: 2 }]);

    assert.deepEqual(cachedMediaPlaylists(7), [{ ...playlist, folder_id: 2 }]);
});

void test('library history records playing events without changing how tracks are prepared', () => {
    const page = readFileSync(
        new URL('../../resources/js/pages/media/Index.vue', import.meta.url),
        'utf8',
    );
    const player = readFileSync(
        new URL(
            '../../resources/js/components/MediaPlayback.vue',
            import.meta.url,
        ),
        'utf8',
    );

    assert.ok(
        player.includes('@playing="recordPlaying"'),
        'only playback start should report a history event',
    );
    assert.match(page, /<MediaPlayback[\s\S]*@played="recordPlayback"/);
    assert.match(page, /preparedDownloadUrl\(id, signal, true\)/);
    assert.match(player, /await readBrowserCopy\(props.account, copy.id\)/);
    assert.match(page, /Recently played/);
    assert.match(page, /Most played/);
});
