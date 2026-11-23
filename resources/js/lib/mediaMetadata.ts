export type MediaDetails = {
    title?: string | null;
    artist?: string | null;
    album?: string | null;
    genre?: string | null;
    track_number?: number | null;
    lyrics?: string | null;
    cover_file_id?: number | null;
    album_cover_file_id?: number | null;
};

const textFrames = {
    TIT2: 'title',
    TPE1: 'artist',
    TALB: 'album',
    TCON: 'genre',
} as const;
const maxTagBytes = 2 * 1024 * 1024;
const ascii = new TextDecoder('ascii');

function syncsafe(bytes: Uint8Array): number {
    if (bytes.some((byte) => byte > 127)) return 0;
    return bytes.reduce((value, byte) => (value << 7) | byte, 0);
}

function decodeText(bytes: Uint8Array, encoding: number): string {
    switch (encoding) {
        case 0:
            return new TextDecoder('iso-8859-1').decode(bytes);
        case 1:
            return new TextDecoder(
                bytes[0] === 254 && bytes[1] === 255 ? 'utf-16be' : 'utf-16le',
            ).decode(bytes);
        case 2:
            return new TextDecoder('utf-16be').decode(bytes);
        case 3:
            return new TextDecoder('utf-8').decode(bytes);
        default:
            return '';
    }
}

export async function readEmbeddedMediaMetadata(
    blob: Blob,
): Promise<MediaDetails> {
    const header = new Uint8Array(await blob.slice(0, 10).arrayBuffer());
    if (
        header.length !== 10 ||
        ascii.decode(header.subarray(0, 3)) !== 'ID3' ||
        ![3, 4].includes(header[3]) ||
        header[5] !== 0
    )
        return {};
    const size = syncsafe(header.subarray(6));
    if (!size || size > maxTagBytes || size + 10 > blob.size) return {};

    const tag = new Uint8Array(await blob.slice(10, 10 + size).arrayBuffer());
    const view = new DataView(tag.buffer);
    const details: MediaDetails = {};

    for (let offset = 0; offset + 10 <= tag.length;) {
        const id = ascii.decode(tag.subarray(offset, offset + 4));
        if (!/^[A-Z0-9]{4}$/.test(id)) break;
        const length =
            header[3] === 4
                ? syncsafe(tag.subarray(offset + 4, offset + 8))
                : view.getUint32(offset + 4);
        const start = offset + 10;
        const end = start + length;
        if (!length || end > tag.length) break;
        const flags = tag[offset + 9];
        offset = end;
        if (flags) continue;

        const value = decodeText(
            tag.subarray(start + (id === 'USLT' ? 4 : 1), end),
            tag[start],
        );
        if (Object.hasOwn(textFrames, id)) {
            const key = textFrames[id as keyof typeof textFrames];
            details[key] = value.split('\0')[0].trim().slice(0, 255);
        } else if (id === 'TRCK') {
            const track = Number(value.split(/[/\0]/)[0]);
            if (Number.isInteger(track) && track >= 1 && track <= 9999)
                details.track_number = track;
        } else if (id === 'USLT') {
            const delimiter = value.indexOf('\0');
            if (delimiter >= 0)
                details.lyrics = value
                    .slice(delimiter + 1)
                    .replace(/\0+$/, '')
                    .slice(0, 10000);
        }
    }

    return details;
}

export async function readEmbeddedMediaStream(
    stream: ReadableStream<Uint8Array<ArrayBuffer>>,
): Promise<MediaDetails> {
    const reader = stream.getReader();
    const chunks: Uint8Array<ArrayBuffer>[] = [];
    let remaining = maxTagBytes + 10;
    try {
        while (remaining > 0) {
            const { done, value } = await reader.read();
            if (done) break;
            const chunk = value.slice(0, remaining);
            chunks.push(chunk);
            remaining -= chunk.byteLength;
        }
        return await readEmbeddedMediaMetadata(new Blob(chunks));
    } finally {
        await reader.cancel().catch(() => {});
        reader.releaseLock();
    }
}
