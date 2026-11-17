import fs from 'node:fs/promises';
import { expect } from '@playwright/test';
import { PEERJS } from './env.js';

/**
 * Points the app's PeerJS client at the local signaling server and drops the
 * public STUN servers, so transfers run entirely inside the test stack.
 *
 * The app constructs `new Peer(id, options)` without a host, which defaults to
 * the PeerJS cloud. This wraps the constructor as soon as peerjs.min.js
 * assigns `window.Peer`.
 */
export async function usePeerServer(context) {
    await context.addInitScript(({ host, port }) => {
        let LocalPeer;
        Object.defineProperty(window, 'Peer', {
            configurable: true,
            get: () => LocalPeer,
            set(Peer) {
                LocalPeer = class extends Peer {
                    constructor(id, options = {}) {
                        super(id, {
                            ...options,
                            host,
                            port,
                            path: '/',
                            secure: false,
                            config: { ...options.config, iceServers: [] },
                        });
                    }
                };
            },
        });
    }, PEERJS);
}

/**
 * Makes WebRTC connections hang instead of opening, and shortens the wait before the pages
 * fall back to the PHP relay. Signaling still works, so the pages try a direct connection first.
 */
export async function blockWebRtc(context, fallbackMs = 1500) {
    await context.addInitScript((ms) => {
        const Real = window.RTCPeerConnection;
        window.RTCPeerConnection = class extends Real {
            createOffer() {
                return new Promise(() => {});
            }

            createAnswer() {
                return new Promise(() => {});
            }
        };
        window.P2P_FALLBACK_MS = ms;
    }, fallbackMs);
}

/**
 * Creates a receive session as the logged-in owner and leaves the page on the
 * waiting screen with its peer ID registered.
 */
export async function startReceiveSession(page) {
    // Visiting /p2p/receive creates a session and redirects to its create page
    await page.goto('/p2p/receive');
    await page.waitForURL(/\/p2p\/receive\/create\//);

    const token = new URL(page.url()).pathname.split('/').pop();
    const pin = (await page.locator('#pin-display').innerText()).trim();
    const shareUrl = await page.locator('#share-url').inputValue();

    await page.goto(`/p2p/receive/waiting/${token}`);
    await expect(page.locator('#status-text')).toHaveText(/Ready and waiting/);

    // The peer ID is registered by a fire-and-forget request after the peer opens
    let peerjsId = '';
    await expect.poll(async () => {
        peerjsId = (await getSession(page.request, token)).peerjsId;
        return peerjsId;
    }).not.toBe('');

    return { token, pin, shareUrl, peerjsId };
}

export async function getSession(request, token) {
    const response = await request.get(`/api/p2p/receive/${token}`);
    expect(response.ok()).toBeTruthy();
    return response.json();
}

export function wrongPin(pin) {
    return pin === '10' ? '11' : '10';
}

/** Opens the guest page and verifies the PIN. */
export async function enterPin(guest, shareUrl, pin) {
    await guest.goto(shareUrl);
    await guest.locator('#pin-input').fill(pin);
    await guest.locator('#verify-pin-btn').click();
}

/** Guest flow: verify PIN, choose files, send, and wait for success. */
export async function sendFiles(guest, shareUrl, pin, files) {
    await enterPin(guest, shareUrl, pin);
    await expect(guest.locator('#file-selection-section')).toBeVisible();

    await guest.locator('#file-input').setInputFiles(files);
    await expect(guest.locator('#send-files-btn')).toBeEnabled();
    await guest.locator('#send-files-btn').click();

    await expect(guest.locator('#success-section')).toBeVisible({ timeout: 45_000 });
}

/**
 * Clicks a "download all" button and returns { fileName: sha256 } for the zip,
 * computed in the page with its own JSZip.
 */
export async function downloadZipHashes(page, buttonSelector) {
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.locator(buttonSelector).click(),
    ]);
    const zip = await fs.readFile(await download.path());

    return page.evaluate(async (base64) => {
        const archive = await JSZip.loadAsync(base64, { base64: true });
        const hashes = {};
        for (const [name, entry] of Object.entries(archive.files)) {
            const digest = await crypto.subtle.digest('SHA-256', await entry.async('uint8array'));
            hashes[name] = [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
        }
        return hashes;
    }, zip.toString('base64'));
}

/** Downloads the reverse-P2P receiver's zip; see downloadZipHashes(). */
export function downloadReceivedHashes(receiver) {
    return downloadZipHashes(receiver, '#download-all-btn');
}
