import crypto from 'node:crypto';
import { test as base, expect } from '@playwright/test';
import { APP_URL, AUTH_FILE } from '../support/env.js';
import {
    blockWebRtc,
    downloadReceivedHashes,
    downloadZipHashes,
    sendFiles,
    startReceiveSession,
    usePeerServer,
} from '../support/receive.js';

// WebRTC is blocked on both pages, so the transfer can only succeed through the PHP relay
const test = base.extend({
    sender: async ({ browser }, use) => {
        const context = await browser.newContext({ baseURL: APP_URL, storageState: AUTH_FILE });
        await usePeerServer(context);
        await blockWebRtc(context);
        await use(await context.newPage());
        await context.close();
    },
    receiver: async ({ browser }, use) => {
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

test.describe('P2P relay fallback', () => {
    test('files arrive intact through the relay when WebRTC cannot connect', async ({ sender, guest }) => {
        const files = [
            { name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('Relayed by the e2e sender\n') },
            { name: 'random.bin', mimeType: 'application/octet-stream', buffer: crypto.randomBytes(3 * 1024 * 1024 + 123) },
        ];

        const { pin, shareUrl } = await startShare(sender, files);
        // The encryption key rides in the fragment, which browsers never send to the server
        expect(shareUrl).toMatch(/\/p2p\/share\/[A-Za-z0-9]+#k=[A-Za-z0-9_-]{43}$/);

        const relayRequests = [];
        guest.on('request', (request) => {
            if (request.url().includes('/api/p2p/relay/')) {
                relayRequests.push(request);
            }
        });

        await guest.goto(shareUrl);
        await guest.locator('#pin-input').fill(pin);
        await guest.locator('#verify-pin-btn').click();

        await expect(guest.locator('#connection-status')).toContainText('All files received', { timeout: 60_000 });
        expect(relayRequests.length).toBeGreaterThan(0);

        // The server only ever saw ciphertext
        for (const request of relayRequests) {
            expect(request.url()).not.toContain('#k=');
            const body = request.postDataBuffer();
            if (body && body.length > 1024) {
                expect(body.includes(files[1].buffer.subarray(0, 4096))).toBe(false);
            }
        }

        const received = await downloadZipHashes(guest, '#download-all-zip-btn');
        expect(received).toEqual(Object.fromEntries(files.map((f) => [f.name, sha256(f.buffer)])));
    });

    test('the guest still receives files when IndexedDB is unavailable', async ({ sender, guest }) => {
        // Simulates a browser (such as Firefox private browsing) where IndexedDB cannot be opened
        await guest.context().addInitScript(() => {
            indexedDB.open = () => {
                const request = {};
                setTimeout(() => request.onerror && request.onerror(new Event('error')), 0);
                return request;
            };
        });

        const files = [
            { name: 'memory.txt', mimeType: 'text/plain', buffer: Buffer.from('Kept in memory\n') },
            { name: 'random.bin', mimeType: 'application/octet-stream', buffer: crypto.randomBytes(1024 * 1024 + 5) },
        ];

        const { pin, shareUrl } = await startShare(sender, files);
        const dropRequests = [];
        guest.on('request', (request) => {
            if (request.url().includes('/drop')) {
                dropRequests.push(request.url());
            }
        });
        await guest.goto(shareUrl);
        await guest.locator('#pin-input').fill(pin);
        await guest.locator('#verify-pin-btn').click();

        await expect(guest.locator('#connection-status')).toContainText('All files received', { timeout: 60_000 });
        // Without IndexedDB the guest asks the sender to leave the files on the server and downloads them from there
        expect(dropRequests.length).toBeGreaterThan(0);

        const received = await downloadZipHashes(guest, '#download-all-zip-btn');
        expect(received).toEqual(Object.fromEntries(files.map((f) => [f.name, sha256(f.buffer)])));
    });

    test('files left on the server can be downloaded after the sender has gone', async ({ sender, guest }) => {
        const files = [
            { name: 'later.txt', mimeType: 'text/plain', buffer: Buffer.from('Downloaded after the sender left\n') },
            { name: 'random.bin', mimeType: 'application/octet-stream', buffer: crypto.randomBytes(2 * 1024 * 1024 + 11) },
        ];

        const { pin, shareUrl } = await startShare(sender, files);

        await sender.locator('#leave-files-btn').click();
        await expect(sender.locator('#drop-status')).toContainText('Files stored on the server', { timeout: 60_000 });

        // The sender goes offline: nothing but the server's copy remains
        await sender.context().close();

        const dropRequests = [];
        guest.on('request', (request) => {
            if (request.url().includes('/drop')) {
                dropRequests.push(request.url());
            }
        });

        await guest.goto(shareUrl);
        await guest.locator('#pin-input').fill(pin);
        await guest.locator('#verify-pin-btn').click();

        await expect(guest.locator('#connection-status')).toContainText('All files received', { timeout: 60_000 });
        expect(dropRequests.length).toBeGreaterThan(0);

        const received = await downloadZipHashes(guest, '#download-all-zip-btn');
        expect(received).toEqual(Object.fromEntries(files.map((f) => [f.name, sha256(f.buffer)])));
    });

    test('the relay takes over when the PeerJS signaling server cannot be reached', async ({ sender, guest }) => {
        // A Peer that never opens, as when the public PeerJS broker is blocked or unreachable
        await guest.context().route('**/peerjs.min.js*', (route) => route.fulfill({
            contentType: 'text/javascript',
            body: 'window.Peer = function () { this.open = false; this.on = function () {}; this.connect = function () {}; this.destroy = function () {}; };',
        }));

        const files = [{ name: 'signal.txt', mimeType: 'text/plain', buffer: Buffer.from('No signaling server needed\n') }];
        const { pin, shareUrl } = await startShare(sender, files);

        await guest.goto(shareUrl);
        await guest.locator('#pin-input').fill(pin);
        await guest.locator('#verify-pin-btn').click();

        await expect(guest.locator('#connection-status')).toContainText('All files received', { timeout: 60_000 });
    });

    test('the relay takes over when the direct connection opens but carries no data', async ({ sender, guest }) => {
        // A direct connection that reports "open" and then delivers nothing (typical on mobile networks)
        await guest.context().route('**/peerjs.min.js*', (route) => route.fulfill({
            contentType: 'text/javascript',
            body: `window.Peer = function () {
                this.open = true;
                this.on = function (event, listener) { if (event === 'open') setTimeout(function () { listener('stub'); }, 0); };
                this.destroy = function () {};
                this.connect = function (id) {
                    const listeners = {};
                    const conn = {
                        peer: id, open: false,
                        on: function (event, listener) { (listeners[event] = listeners[event] || []).push(listener); return conn; },
                        send: function () {}, close: function () { conn.open = false; },
                    };
                    setTimeout(function () { conn.open = true; (listeners.open || []).forEach(function (l) { l(); }); }, 50);
                    return conn;
                };
            };`,
        }));

        const files = [{ name: 'stalled.txt', mimeType: 'text/plain', buffer: Buffer.from('Rescued by the relay\n') }];
        const { pin, shareUrl } = await startShare(sender, files);

        await guest.goto(shareUrl);
        await guest.locator('#pin-input').fill(pin);
        await guest.locator('#verify-pin-btn').click();

        await expect(guest.locator('#connection-status')).toContainText('All files received', { timeout: 60_000 });
    });

    test('an unreadable file is reported on both sides instead of "sent"', async ({ sender, guest }) => {
        // Reading the 777 byte file fails, as when a selected file was moved or deleted on disk
        await sender.context().addInitScript(() => {
            const original = Blob.prototype.arrayBuffer;
            Blob.prototype.arrayBuffer = function () {
                return this.size === 777 ? Promise.reject(new Error('NotReadableError')) : original.call(this);
            };
        });

        const files = [{ name: 'broken.bin', mimeType: 'application/octet-stream', buffer: crypto.randomBytes(777) }];
        const { pin, shareUrl } = await startShare(sender, files);

        await guest.goto(shareUrl);
        await guest.locator('#pin-input').fill(pin);
        await guest.locator('#verify-pin-btn').click();

        await expect(guest.locator('#connection-status')).toContainText('could not read', { timeout: 30_000 });
        await expect(sender.locator('#connection-status')).toContainText('Could not read broken.bin');
        await expect(guest.locator('#connection-status')).not.toContainText('All files received');
    });

    test('a guest sends files to the receiver through the relay', async ({ receiver, guest }) => {
        const session = await startReceiveSession(receiver);
        expect(session.shareUrl).toMatch(/#k=[A-Za-z0-9_-]{43}$/);

        const files = [
            { name: 'hello.txt', mimeType: 'text/plain', buffer: Buffer.from('Relayed from the e2e guest\n') },
            { name: 'random.bin', mimeType: 'application/octet-stream', buffer: crypto.randomBytes(2 * 1024 * 1024 + 7) },
        ];

        await sendFiles(guest, session.shareUrl, session.pin, files);

        const list = receiver.locator('#received-files-list');
        for (const file of files) {
            await expect(list).toContainText(file.name);
        }

        const received = await downloadReceivedHashes(receiver);
        expect(received).toEqual(Object.fromEntries(files.map((f) => [f.name, sha256(f.buffer)])));
    });

    test('an admin can turn the relay off and set a size cap', async ({ sender }) => {
        const save = (form) => sender.request.post('/admin/settings/preview', { form: { action: 'update_p2p_relay', ...form } });
        const capabilities = async () => (await sender.request.get('/api/p2p/relay/capabilities')).json();

        try {
            expect((await save({ p2p_relay_enabled: '0', p2p_relay_max_mb: '25' })).ok()).toBeTruthy();
            expect(await capabilities()).toMatchObject({ enabled: false, maxBytes: 25 * 1024 * 1024 });

            expect((await save({ p2p_relay_enabled: '1', p2p_relay_max_mb: '0' })).status()).toBe(400);
            expect((await save({ p2p_relay_enabled: '1', p2p_relay_max_mb: 'lots' })).status()).toBe(400);
            // A rejected save changes nothing
            expect(await capabilities()).toMatchObject({ enabled: false, maxBytes: 25 * 1024 * 1024 });
        } finally {
            await save({ p2p_relay_enabled: '1', p2p_relay_max_mb: '1024' });
        }
        expect(await capabilities()).toMatchObject({ enabled: true, maxBytes: 1024 * 1024 * 1024 });
    });

    test('a file larger than the server buffer still arrives, the sender just waits for room', async ({ sender, guest }) => {
        const save = (maxMb) => sender.request.post('/admin/settings/preview', {
            form: { action: 'update_p2p_relay', p2p_relay_enabled: '1', p2p_relay_max_mb: String(maxMb) },
        });

        try {
            expect((await save(1)).ok()).toBeTruthy();
            const files = [{ name: 'big.bin', mimeType: 'application/octet-stream', buffer: crypto.randomBytes(5 * 1024 * 1024 + 3) }];
            const { pin, shareUrl } = await startShare(sender, files);

            await guest.goto(shareUrl);
            await guest.locator('#pin-input').fill(pin);
            await guest.locator('#verify-pin-btn').click();

            await expect(guest.locator('#connection-status')).toContainText('All files received', { timeout: 60_000 });
            const received = await downloadZipHashes(guest, '#download-all-zip-btn');
            expect(received).toEqual({ 'big.bin': sha256(files[0].buffer) });
        } finally {
            await save(1024);
        }
    });

    test('files left for later that exceed the size limit are refused', async ({ sender }) => {
        const save = (maxMb) => sender.request.post('/admin/settings/preview', {
            form: { action: 'update_p2p_relay', p2p_relay_enabled: '1', p2p_relay_max_mb: String(maxMb) },
        });

        try {
            expect((await save(1)).ok()).toBeTruthy();
            await startShare(sender, [
                { name: 'big.bin', mimeType: 'application/octet-stream', buffer: crypto.randomBytes(3 * 1024 * 1024) },
            ]);
            await sender.locator('#leave-files-btn').click();
            await expect(sender.locator('#drop-status')).toContainText('size limit', { timeout: 30_000 });
        } finally {
            await save(1024);
        }
    });

    test('a link without the key explains why the relay cannot be used', async ({ sender, guest }) => {
        const { pin, shareUrl } = await startShare(sender, [
            { name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('no key\n') },
        ]);

        await guest.goto(shareUrl.split('#')[0]);
        await guest.locator('#pin-input').fill(pin);
        await guest.locator('#verify-pin-btn').click();

        await expect(guest.locator('#connection-status')).toContainText('complete share link', { timeout: 30_000 });
    });

    test('the relay refuses visitors who have not verified the PIN', async ({ sender, browser }) => {
        await startShare(sender, [{ name: 'a.txt', mimeType: 'text/plain', buffer: Buffer.from('a\n') }]);
        const { sessions } = await (await sender.request.get('/api/p2p/share')).json();
        const token = sessions[0]?.token;
        expect(token).toBeTruthy();

        const stranger = await browser.newContext({ baseURL: APP_URL });
        const response = await stranger.request.post(`/api/p2p/relay/share/${token}/channels`);
        expect(response.status()).toBe(403);
        await stranger.close();
    });
});
