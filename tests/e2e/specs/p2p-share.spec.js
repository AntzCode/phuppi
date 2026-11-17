import crypto from 'node:crypto';
import { test as base, expect } from '@playwright/test';
import { APP_URL, AUTH_FILE } from '../support/env.js';
import { downloadZipHashes, usePeerServer } from '../support/receive.js';

// The logged-in sender shares files; an anonymous guest downloads them
const test = base.extend({
    sender: async ({ browser }, use) => {
        const context = await browser.newContext({ baseURL: APP_URL, storageState: AUTH_FILE });
        await usePeerServer(context);
        await use(await context.newPage());
        await context.close();
    },
    guest: async ({ browser }, use) => {
        const context = await browser.newContext({ baseURL: APP_URL });
        await usePeerServer(context);
        await use(await context.newPage());
        await context.close();
    },
});

const sha256 = (buffer) => crypto.createHash('sha256').update(buffer).digest('hex');

test.describe('P2P file sharing', () => {
    test('guest downloads the files the sender shared', async ({ sender, guest }) => {
        const files = [
            { name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('Shared by the e2e sender\n') },
            { name: 'random.bin', mimeType: 'application/octet-stream', buffer: crypto.randomBytes(2 * 1024 * 1024) },
        ];

        await sender.goto('/p2p/share');
        await sender.locator('#file-input').setInputFiles(files);

        const createButton = sender.locator('#create-share-btn');
        await expect(createButton).toBeEnabled();
        await createButton.click();

        await expect(sender.locator('#share-section')).toBeVisible();
        await expect(sender.locator('#connection-status')).toContainText('Ready!');
        const pin = (await sender.locator('#pin-display').innerText()).trim();
        const shareUrl = await sender.locator('#share-url').inputValue();
        expect(new URL(shareUrl).pathname).toMatch(/^\/p2p\/share\/[A-Za-z0-9]+$/);

        await guest.goto(shareUrl);
        await guest.locator('#pin-input').fill(pin);
        await guest.locator('#verify-pin-btn').click();

        await expect(guest.locator('#connection-status')).toContainText('All files received', { timeout: 45_000 });

        const received = await downloadZipHashes(guest, '#download-all-zip-btn');
        expect(received).toEqual(Object.fromEntries(files.map((f) => [f.name, sha256(f.buffer)])));
    });

    test('the sender page reopens the share after a reload', async ({ sender }) => {
        await sender.goto('/p2p/share');
        await sender.locator('#file-input').setInputFiles({ name: 'a.txt', mimeType: 'text/plain', buffer: Buffer.from('a') });
        await sender.locator('#create-share-btn').click();
        await expect(sender.locator('#connection-status')).toContainText('Ready!');
        const pin = (await sender.locator('#pin-display').innerText()).trim();

        await sender.reload();

        await expect(sender.locator('#share-section')).toBeVisible();
        await expect(sender.locator('#pin-display')).toHaveText(pin);
    });
});
