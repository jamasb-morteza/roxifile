import assert from 'node:assert/strict';
import test from 'node:test';

const tracks = [
    {
        id: 1,
        name: 'rain.mp3',
        protected: false,
        details: {
            title: 'After Rain',
            artist: 'The Pines',
            album: 'Night Songs',
            genre: 'Folk',
        },
    },
    {
        id: 2,
        name: 'dawn.mp3',
        protected: false,
        details: {
            title: 'At Dawn',
            artist: ' the pines ',
            album: 'Night Songs',
            genre: 'Folk',
        },
    },
    { id: 3, name: 'Birdsong.ogg', protected: false },
    {
        id: 4,
        name: 'Private filename',
        protected: true,
        details: {
            title: 'Private title',
            artist: 'Private artist',
            album: 'Private album',
            genre: 'Private genre',
        },
    },
];

void test('discovery updates track matches by title, filename, artist and album as the query changes', async () => {
    const { discoverMediaTracks } =
        await import('../../resources/js/lib/mediaDiscovery.ts');
    const matches = (query) =>
        discoverMediaTracks(tracks, query, null).map((track) => track.id);

    assert.deepEqual(matches(' after rai '), [1]);
    assert.deepEqual(matches('DAWN.MP3'), [2]);
    assert.deepEqual(matches('The Pin'), [1, 2]);
    assert.deepEqual(matches('Night Song'), [1, 2]);
    assert.deepEqual(matches('birdsong'), [3]);
    assert.deepEqual(matches(''), [1, 2, 3, 4]);
});

void test('discovery groups browseable artist, album and genre labels case-insensitively', async () => {
    const { discoverMediaTracks, mediaDiscoveryGroups } =
        await import('../../resources/js/lib/mediaDiscovery.ts');
    assert.deepEqual(mediaDiscoveryGroups(tracks, ''), {
        artist: [{ key: 'the pines', name: 'The Pines' }],
        album: [{ key: 'night songs', name: 'Night Songs' }],
        genre: [{ key: 'folk', name: 'Folk' }],
    });
    assert.deepEqual(mediaDiscoveryGroups(tracks, 'night'), {
        artist: [],
        album: [{ key: 'night songs', name: 'Night Songs' }],
        genre: [],
    });
    for (const [field, key] of [
        ['artist', 'the pines'],
        ['album', 'night songs'],
        ['genre', 'folk'],
    ]) {
        assert.deepEqual(
            discoverMediaTracks(tracks, '', { field, key }).map(
                (track) => track.id,
            ),
            [1, 2],
        );
    }
});

void test('protected track filenames and metadata never participate in discovery', async () => {
    const { discoverMediaTracks, mediaDiscoveryGroups } =
        await import('../../resources/js/lib/mediaDiscovery.ts');
    assert.deepEqual(discoverMediaTracks(tracks, 'private', null), []);
    assert.deepEqual(discoverMediaTracks(tracks, 'protected file 4', null), []);
    assert.deepEqual(mediaDiscoveryGroups(tracks, 'private'), {
        artist: [],
        album: [],
        genre: [],
    });
});

void test('discovery keeps Telegram and offline playback information on tracks from the current catalog', async () => {
    const { mediaDiscoverySource, discoverMediaTracks } =
        await import('../../resources/js/lib/mediaDiscovery.ts');
    const remote = { ...tracks[0], discovered: true, encrypted: true };
    const saved = { ...tracks[1], discovered: true, localOnly: true };
    const browserOnly = { ...tracks[2], discovered: false, localOnly: true };

    assert.deepEqual(
        discoverMediaTracks(
            mediaDiscoverySource([remote, saved], false),
            'rain',
            null,
        ),
        [remote],
    );
    assert.deepEqual(mediaDiscoverySource([saved, browserOnly], true), [saved]);
    assert.deepEqual(
        discoverMediaTracks(
            mediaDiscoverySource([saved, browserOnly], false),
            'birdsong',
            null,
        ),
        [browserOnly],
    );
});
