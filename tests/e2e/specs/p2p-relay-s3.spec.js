import crypto from 'node:crypto';
import { test as base, expect } from '@playwright/test';
import { APP_URL, AUTH_FILE } from '../support/env.js';
import { blockWebRtc, downloadZipHashes, usePeerServer } from '../support/receive.js';

// The relay keeps its batches on the active storage connector: with S3 active they go to the bucket
// (served by the stack's Garage) instead of the web server's disk.
const test = base.extend({
    sender: async ({ browser }, use) => {
        const context = await browser.newContext({ baseURL: APP_URL, storageState: AUTH_FILE });
        await usePeerServer(context);
        await blockWebRtc(context);
        await use(await context.newPage());
        await context.close();
    },
    guest: async ({ browser }, use) => {
        const context = await browser.newContext({ baseURL: APP_URL });
        await usePeerServer(context);
        await blockWebRtc(context);
        await use(await context.newPage());
        await context.close();
    },
});

const sha256 = (buffer) => crypto.createHash('sha256').update(buffer).digest('hex');

async function setActiveConnector(playwright, name) {
    const request = await playwright.request.newContext({ baseURL: APP_URL, storageState: AUTH_FILE });
    const response = await request.post('/admin/settings/storage', { form: { action: 'set_active', connector_name: name } });
    expect(response.ok()).toBeTruthy();
    await request.dispose();
}

async function startShare(sender, files) {
    await sender.goto('/p2p/share');
    await sender.locator('#file-input').setInputFiles(files);
    await expect(sender.locator('#create-share-btn')).toBeEnabled();
    await sender.locator('#create-share-btn').click();
    await expect(sender.locator('#share-section')).toBeVisible();
    await expect(sender.locator('#connection-status')).toContainText('Ready!');

    return {
        pin: (await sender.locator('#pin-display').innerText()).trim(),
        shareUrl: await sender.locator('#share-url').inputValue(),
    };
}

test.describe('P2P relay on S3 storage', () => {
    test.beforeAll(async ({ playwright }) => setActiveConnector(playwright, 's3-dev'));
    test.afterAll(async ({ playwright }) => setActiveConnector(playwright, 'local-default'));

    test('a live relay transfer goes through the bucket intact', async ({ sender, guest }) => {
        const files = [{ name: 's3-live.bin', mimeType: 'application/octet-stream', buffer: crypto.randomBytes(6 * 1024 * 1024 + 17) }];
        const { pin, shareUrl } = await startShare(sender, files);

        await guest.goto(shareUrl);
        await guest.locator('#pin-input').fill(pin);
        await guest.locator('#verify-pin-btn').click();

        await expect(guest.locator('#connection-status')).toContainText('All files received', { timeout: 90_000 });
        expect(await downloadZipHashes(guest, '#download-all-zip-btn')).toEqual({ 's3-live.bin': sha256(files[0].buffer) });
    });

    test('files left for later are downloaded from the bucket after the sender has gone', async ({ sender, guest }) => {
        const files = [{ name: 's3-later.bin', mimeType: 'application/octet-stream', buffer: crypto.randomBytes(3 * 1024 * 1024 + 5) }];
        const { pin, shareUrl } = await startShare(sender, files);

        await sender.locator('#leave-files-btn').click();
        await expect(sender.locator('#drop-status')).toContainText('Files stored on the server', { timeout: 90_000 });
        await sender.context().close();

        await guest.goto(shareUrl);
        await guest.locator('#pin-input').fill(pin);
        await guest.locator('#verify-pin-btn').click();

        await expect(guest.locator('#connection-status')).toContainText('All files received', { timeout: 90_000 });
        expect(await downloadZipHashes(guest, '#download-all-zip-btn')).toEqual({ 's3-later.bin': sha256(files[0].buffer) });
    });
});
