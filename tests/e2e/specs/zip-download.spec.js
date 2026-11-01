import { execFileSync } from 'node:child_process';
import crypto from 'node:crypto';
import { test, expect } from '@playwright/test';
import { ADMIN, AUTH_FILE } from '../support/env.js';
import { zipEntryHashes } from '../support/zip.js';

test.use({ storageState: AUTH_FILE });

const APP_CONTAINER = process.env.E2E_APP_CONTAINER ?? 'phuppi-e2e-app-1';

const sha256 = (buffer) => crypto.createHash('sha256').update(buffer).digest('hex');

/** Uploads a file through the API and returns its id. */
async function upload(request, name, content) {
    const response = await request.post('/files', {
        multipart: { file: { name, mimeType: 'text/plain', buffer: content } },
    });
    expect(response.ok()).toBeTruthy();
    const [result] = await response.json();
    expect(result.id, JSON.stringify(result)).toBeTruthy();
    return result.id;
}

function uniqueFile(label) {
    const name = `zip-${label}-${Date.now()}-${crypto.randomBytes(3).toString('hex')}.txt`;
    const content = Buffer.from(`content of ${name}\n`.repeat(200));
    return { name, content };
}

test.describe('Zip & Download', () => {
    test('zips the selected files with intact content and sends the zip once', async ({ request }) => {
        const files = [uniqueFile('a'), uniqueFile('b'), uniqueFile('c')];
        const ids = [];
        for (const file of files) {
            ids.push(await upload(request, file.name, file.content));
        }

        const response = await request.post('/files/download', { data: { ids } });
        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toBe('application/zip');

        const body = await response.body();
        // The zip must be sent exactly once: the body is as long as the declared Content-Length
        expect(body.length).toBe(Number(response.headers()['content-length']));

        const hashes = zipEntryHashes(body);
        expect(Object.keys(hashes).sort()).toEqual(files.map((f) => f.name).sort());
        for (const file of files) {
            expect(hashes[file.name]).toBe(sha256(file.content));
        }
    });

    test('rejects the whole zip, naming the file, when one is missing in storage', async ({ request }) => {
        const present = uniqueFile('present');
        const missing = uniqueFile('missing');
        const ids = [await upload(request, present.name, present.content), await upload(request, missing.name, missing.content)];

        // Remove the stored object, leaving its database record
        execFileSync('docker', [
            'exec', APP_CONTAINER, 'sh', '-c',
            `rm -f /var/www/data/uploads/${ADMIN.username}/*_${missing.name}`,
        ]);

        const response = await request.post('/files/download', { data: { ids } });
        expect(response.status()).toBe(502);
        const body = await response.json();
        expect(body.failed).toEqual([missing.name]);
        expect(body.error).toMatch(/could not be read/i);
    });

    test('reports when none of the files can be read', async ({ request }) => {
        const only = uniqueFile('only');
        const id = await upload(request, only.name, only.content);
        execFileSync('docker', [
            'exec', APP_CONTAINER, 'sh', '-c',
            `rm -f /var/www/data/uploads/${ADMIN.username}/*_${only.name}`,
        ]);

        const response = await request.post('/files/download', { data: { ids: [id] } });
        expect(response.status()).toBe(502);
        const body = await response.json();
        expect(body.failed).toEqual([only.name]);
        expect(body.error).toMatch(/none of the selected files/i);
    });

    test('answers missing file ids with JSON', async ({ request }) => {
        const response = await request.post('/files/download', { data: { ids: [] } });
        expect(response.status()).toBe(400);
        expect((await response.json()).error).toMatch(/file ids/i);
    });
});
