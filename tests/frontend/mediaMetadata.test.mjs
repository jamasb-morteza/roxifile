import assert from 'node:assert/strict';
import test from 'node:test';

void test('reads embedded ID3 title, artist, album, genre, track number and lyrics', async () => {
    const { readEmbeddedMediaMetadata, readEmbeddedMediaStream } =
        await import('../../resources/js/lib/mediaMetadata.ts');

    const frame = (id, value) => {
        const body = Buffer.from([3, ...Buffer.from(value)]);
        const header = Buffer.alloc(10);
        header.write(id);
        header.writeUInt32BE(body.length, 4);
        return Buffer.concat([header, body]);
    };
    const frames = Buffer.concat([
        frame('TIT2', 'Track title'),
        frame('TPE1', 'Artist'),
        frame('TALB', 'Album'),
        frame('TCON', 'Jazz'),
        frame('TRCK', '5/9'),
        frame('USLT', 'eng\0Some lyrics'),
    ]);
    const length = frames.length;
    const header = Buffer.from([
        73,
        68,
        51,
        3,
        0,
        0,
        0,
        0,
        length >> 7,
        length & 127,
    ]);
    assert.deepEqual(
        await readEmbeddedMediaMetadata(new Blob([header, frames])),
        {
            title: 'Track title',
            artist: 'Artist',
            album: 'Album',
            genre: 'Jazz',
            track_number: 5,
            lyrics: 'Some lyrics',
        },
    );
    assert.deepEqual(
        await readEmbeddedMediaStream(new Blob([header, frames]).stream()),
        await readEmbeddedMediaMetadata(new Blob([header, frames])),
    );
    assert.deepEqual(
        await readEmbeddedMediaMetadata(new Blob(['not metadata'])),
        {},
    );
    assert.deepEqual(
        await readEmbeddedMediaMetadata(
            new Blob([header, frames.subarray(0, 15)]),
        ),
        {},
    );
});
