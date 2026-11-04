import crypto from 'node:crypto';
import { test, expect, request } from '@playwright/test';
import { ADMIN, AUTH_FILE } from '../support/env.js';

const baseURL = process.env.E2E_APP_URL ?? 'http://localhost:8089';

async function setActiveConnector(name) {
    const api = await request.newContext({ baseURL, storageState: AUTH_FILE });
    const response = await api.post('/admin/settings/storage', { form: { action: 'set_active', connector_name: name } });
    expect(response.ok()).toBeTruthy();
    await api.dispose();
}

// Acts like the Android share sheet: POSTs multipart files to the manifest's share_target action.
// The service worker must be controlling the page for the POST to be intercepted.
async function shareFiles(page, files) {
    await page.goto('/login');
    await page.evaluate(async () => {
        await navigator.serviceWorker.ready;
        if (!navigator.serviceWorker.controller) {
            await new Promise((resolve) => navigator.serviceWorker.addEventListener('controllerchange', resolve, { once: true }));
        }
    });
    await page.evaluate(async (items) => {
        const formData = new FormData();
        for (const item of items) {
            formData.append('files', new File([new Uint8Array(item.bytes)], item.name, { type: item.type }));
        }
        await fetch('/share-target', { method: 'POST', body: formData });
    }, files.map((f) => ({ name: f.name, type: f.type, bytes: [...f.buffer] })));
}

const sample = (prefix) => ({
    name: `${prefix}-${Date.now()}.txt`,
    type: 'text/plain',
    buffer: crypto.randomBytes(64),
});

async function expectInFileList(request, name) {
    await expect.poll(async () => {
        const list = await (await request.get('/files?limit=50&sort=date_newest')).json();
        return list.files.some((f) => f.display_filename === name);
    }, { timeout: 30_000 }).toBe(true);
}

test.describe('Share target', () => {
    test('serves a manifest that declares the share target and a service worker', async ({ request }) => {
        const manifest = await (await request.get('/manifest.webmanifest')).json();
        expect(manifest.display).toBe('standalone');
        expect(manifest.share_target.action).toBe('/share-target');
        expect(manifest.share_target.method).toBe('POST');
        expect(manifest.share_target.enctype).toBe('multipart/form-data');

        const worker = await request.get('/sw.js');
        expect(worker.ok()).toBeTruthy();
        expect(worker.headers()['content-type']).toContain('javascript');
    });

    test.describe('logged in', () => {
        test.use({ storageState: AUTH_FILE });

        test('uploads shared files to the account (local storage)', async ({ page, request }) => {
            const files = [sample('share-a'), sample('share-b')];
            await shareFiles(page, files);

            await page.goto('/share-target/receive');
            await expect(page.locator('#share-list .item')).toHaveCount(2);
            await expect(page.locator('#share-status')).toHaveText(/All 2 uploaded/);
            await expect(page.locator('#share-done')).toBeVisible();

            for (const file of files) await expectInFileList(request, file.name);

            // Uploaded files are cleared from the browser: a reload finds nothing waiting
            await page.reload();
            await expect(page.locator('#share-empty')).toBeVisible();
        });

        test('shows a message when nothing was shared', async ({ page }) => {
            await page.goto('/share-target/receive');
            await expect(page.locator('#share-empty')).toBeVisible();
        });
    });

    test.describe('logged in, S3 storage', () => {
        test.use({ storageState: AUTH_FILE });
        test.beforeAll(() => setActiveConnector('s3-dev'));
        test.afterAll(() => setActiveConnector('local-default'));

        test('uploads shared files through a presigned URL', async ({ page, request }) => {
            const file = sample('share-s3');
            await shareFiles(page, [file]);

            await page.goto('/share-target/receive');
            await expect(page.locator('#share-status')).toHaveText(/All 1 uploaded/);
            await expectInFileList(request, file.name);
        });
    });

    test.describe('logged out', () => {
        test('keeps the shared files through the login and uploads them afterwards', async ({ page, request }) => {
            const file = sample('share-login');
            await shareFiles(page, [file]);

            await page.goto('/share-target/receive');
            await expect(page).toHaveURL(/\/login$/);

            await page.locator('input[name="username"]').fill(ADMIN.username);
            await page.locator('input[name="password"]').fill(ADMIN.password);
            await page.locator('form input[value="user"] ~ button[type="submit"], form:has(input[value="user"]) button[type="submit"]').first().click();

            await expect(page).toHaveURL(/\/share-target\/receive$/);
            await expect(page.locator('#share-status')).toHaveText(/All 1 uploaded/);

            const authed = await page.context().request.get('/files?limit=50&sort=date_newest');
            const list = await authed.json();
            expect(list.files.some((f) => f.display_filename === file.name)).toBe(true);
        });
    });
});
