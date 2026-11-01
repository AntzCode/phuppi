import crypto from 'node:crypto';
import zlib from 'node:zlib';

/**
 * Reads a zip archive (stored or deflated entries) and returns { name: sha256 }.
 * Minimal on purpose: walks the central directory, so no zip dependency is needed.
 */
export function zipEntryHashes(zip) {
    let eocd = zip.length - 22;
    while (eocd >= 0 && zip.readUInt32LE(eocd) !== 0x06054b50) {
        eocd--;
    }
    if (eocd < 0) {
        throw new Error('Not a zip archive: end-of-central-directory record not found');
    }

    const count = zip.readUInt16LE(eocd + 10);
    let offset = zip.readUInt32LE(eocd + 16);
    const hashes = {};

    for (let i = 0; i < count; i++) {
        const method = zip.readUInt16LE(offset + 10);
        const compressedSize = zip.readUInt32LE(offset + 20);
        const nameLength = zip.readUInt16LE(offset + 28);
        const extraLength = zip.readUInt16LE(offset + 30);
        const commentLength = zip.readUInt16LE(offset + 32);
        const localOffset = zip.readUInt32LE(offset + 42);
        const name = zip.toString('utf8', offset + 46, offset + 46 + nameLength);

        const localNameLength = zip.readUInt16LE(localOffset + 26);
        const localExtraLength = zip.readUInt16LE(localOffset + 28);
        const start = localOffset + 30 + localNameLength + localExtraLength;
        const raw = zip.subarray(start, start + compressedSize);
        const content = method === 8 ? zlib.inflateRawSync(raw) : raw;

        hashes[name] = crypto.createHash('sha256').update(content).digest('hex');
        offset += 46 + nameLength + extraLength + commentLength;
    }
    return hashes;
}
