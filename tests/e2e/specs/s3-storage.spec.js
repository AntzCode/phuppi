import crypto from 'node:crypto';
import { test, expect } from '@playwright/test';
import { AUTH_FILE } from '../support/env.js';
import { zipEntryHashes } from '../support/zip.js';

test.use({ storageState: AUTH_FILE });

const sha256 = (buffer) => crypto.createHash('sha256').update(buffer).digest('hex');

async function setActiveConnector(request, name) {
    const response = await request.post('/admin/settings/storage', {
        form: { action: 'set_active', connector_name: name },
    });
    expect(response.ok()).toBeTruthy();
}

// These specs run against the S3 connector ("s3-dev", served by the stack's Garage)
test.describe('S3 storage', () => {
    test.beforeAll(async ({ playwright }) => {
        const request = await playwright.request.newContext({ baseURL: process.env.E2E_APP_URL ?? 'http://localhost:8089', storageState: AUTH_FILE });
        await setActiveConnector(request, 's3-dev');
        await request.dispose();
    });

    test.afterAll(async ({ playwright }) => {
        const request = await playwright.request.newContext({ baseURL: process.env.E2E_APP_URL ?? 'http://localhost:8089', storageState: AUTH_FILE });
        await setActiveConnector(request, 'local-default');
        await request.dispose();
    });

    test('browser upload through a presigned URL, ranged download, and zip', async ({ page, request }) => {
        const name = `s3-${Date.now()}.bin`;
        const content = crypto.randomBytes(2 * 1024 * 1024);

        await page.goto('/');
        await page.locator('#toggle-upload-form').click();
        await page.locator('#upload').setInputFiles({ name, mimeType: 'application/octet-stream', buffer: content });
        await page.locator('button.submit', { hasText: /upload/i }).click();

        // The browser PUTs to Garage with a presigned URL, then registers the file
        let file;
        await expect.poll(async () => {
            const list = await (await request.get('/files?limit=50&sort=date_newest')).json();
            file = list.files.find((f) => f.display_filename === name);
            return Boolean(file);
        }, { timeout: 30_000 }).toBe(true);

        // Full download is streamed from S3 byte for byte
        const full = await request.get(`/files/${file.id}`);
        expect(full.status()).toBe(200);
        expect(sha256(await full.body())).toBe(sha256(content));

        // Range request: 206 with exactly the requested bytes
        const part = await request.get(`/files/${file.id}`, { headers: { Range: 'bytes=1048576-1048675' } });
        expect(part.status()).toBe(206);
        expect(part.headers()['content-range']).toBe(`bytes 1048576-1048675/${content.length}`);
        expect(Buffer.compare(await part.body(), content.subarray(1048576, 1048676))).toBe(0);

        // Zip & Download reads the object back from S3
        const zip = await request.post('/files/download', { data: { ids: [file.id] } });
        expect(zip.status()).toBe(200);
        expect(zipEntryHashes(await zip.body())).toEqual({ [name]: sha256(content) });

        // Deleting removes the object from the bucket
        const del = await request.delete('/files', { data: { ids: [file.id] } });
        expect(del.ok()).toBeTruthy();
        const after = await request.get(`/files/${file.id}`);
        expect(after.status()).not.toBe(200);
    });
});
