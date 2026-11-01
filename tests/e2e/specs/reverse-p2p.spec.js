import crypto from 'node:crypto';
import { test as base, expect } from '@playwright/test';
import { APP_URL, AUTH_FILE } from '../support/env.js';
import {
    downloadReceivedHashes,
    enterPin,
    getSession,
    sendFiles,
    startReceiveSession,
    usePeerServer,
    wrongPin,
} from '../support/receive.js';

// Two separate browser contexts: the logged-in receiver and an anonymous guest
const test = base.extend({
    receiver: async ({ browser }, use) => {
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

test.describe('Reverse P2P file receiving', () => {
    test('guest sends files to the receiver intact', async ({ receiver, guest }) => {
        const session = await startReceiveSession(receiver);

        const files = [
            { name: 'hello.txt', mimeType: 'text/plain', buffer: Buffer.from('Hello from the e2e guest\n') },
            // Large enough to span many chunks
            { name: 'random.bin', mimeType: 'application/octet-stream', buffer: crypto.randomBytes(3 * 1024 * 1024) },
        ];

        await sendFiles(guest, session.shareUrl, session.pin, files);

        const list = receiver.locator('#received-files-list');
        for (const file of files) {
            await expect(list).toContainText(file.name);
        }

        const received = await downloadReceivedHashes(receiver);
        expect(received).toEqual(Object.fromEntries(files.map((f) => [f.name, sha256(f.buffer)])));
    });

    test('receiver peer ID stays hidden until the PIN is verified', async ({ receiver, guest }) => {
        const session = await startReceiveSession(receiver);

        await guest.goto(session.shareUrl);
        expect((await getSession(guest.request, session.token)).peerjsId).toBe('');

        await enterPin(guest, session.shareUrl, wrongPin(session.pin));
        await expect(guest.locator('#pin-error')).toBeVisible();
        expect((await getSession(guest.request, session.token)).peerjsId).toBe('');

        await enterPin(guest, session.shareUrl, session.pin);
        await expect(guest.locator('#file-selection-section')).toBeVisible();
        expect((await getSession(guest.request, session.token)).peerjsId).toBe(session.peerjsId);
    });

    test('PIN locks after repeated wrong attempts', async ({ receiver, guest }) => {
        const session = await startReceiveSession(receiver);
        const verify = (pin) => guest.request.post(`/api/p2p/receive/${session.token}/verify-pin`, { data: { pin } });

        let result;
        for (let i = 0; i < 3; i++) {
            result = await (await verify(wrongPin(session.pin))).json();
            expect(result.valid).toBe(false);
        }
        expect(result.locked).toBe(true);

        // Even the correct PIN is refused once locked
        const afterLock = await (await verify(session.pin)).json();
        expect(afterLock.valid).toBe(false);
        expect(afterLock.locked).toBe(true);
        expect((await getSession(guest.request, session.token)).peerjsId).toBe('');
    });

    test('guests cannot overwrite the peer ID or the file list', async ({ receiver, guest }) => {
        const session = await startReceiveSession(receiver);

        // A PIN-verified guest is still not the owner
        await enterPin(guest, session.shareUrl, session.pin);
        await expect(guest.locator('#file-selection-section')).toBeVisible();

        const connect = await guest.request.post(`/api/p2p/receive/${session.token}/connect`, {
            data: { peerjs_id: 'attacker-peer' },
            maxRedirects: 0,
        });
        expect(connect.ok()).toBe(false);

        const complete = await guest.request.post(`/api/p2p/receive/${session.token}/complete`, {
            data: { files: [{ name: 'forged.exe', size: 1, type: 'application/octet-stream' }] },
            maxRedirects: 0,
        });
        expect(complete.ok()).toBe(false);

        const owner = await getSession(receiver.request, session.token);
        expect(owner.peerjsId).toBe(session.peerjsId);
        expect(owner.files).toEqual([]);
    });

    test('file names from the guest are shown as text, not HTML', async ({ receiver, guest }) => {
        const session = await startReceiveSession(receiver);
        const name = '<img src=x onerror="window.__xss=true">.txt';

        await sendFiles(guest, session.shareUrl, session.pin, [
            { name, mimeType: 'text/plain', buffer: Buffer.from('payload') },
        ]);

        await expect(receiver.locator('#received-files-list')).toContainText(name);
        expect(await receiver.evaluate(() => window.__xss)).toBeUndefined();
    });
});
