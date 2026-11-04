import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';
import { AUTH_FILE } from '../support/env.js';

test.use({ storageState: AUTH_FILE });

const fixture = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'fixtures', 'exif-rotated.jpg');

async function upload(request, name, mimeType, buffer) {
    const response = await request.post('/files', { multipart: { file: { name, mimeType, buffer } } });
    expect(response.ok()).toBeTruthy();
    const list = await (await request.get('/files?limit=100&sort=date_newest')).json();
    return list.files.find((f) => f.display_filename === name);
}

const regenerate = (request, form) => request.post('/admin/settings/preview', { form });

test.describe('Regenerate previews by file extension', () => {
    test('the extension box is disabled until its checkbox is ticked, and needs a value', async ({ page }) => {
        await page.goto('/admin/settings');
        await page.locator('a.item[data-tab="preview-settings"]').click();

        const toggle = page.locator('#regenerateExtensionsToggle');
        const input = page.locator('#regenerateExtensions');
        await expect(input).toBeDisabled();

        await page.locator('label[for="regenerateExtensionsToggle"]').click();
        await expect(toggle).toBeChecked();
        await expect(input).toBeEnabled();

        // Ticked but empty: no request is sent, the user is told what to enter
        await page.locator('#regenerateAllBtn').click();
        await expect(page.locator('#previewActionResult')).toContainText('Enter at least one file extension');

        await page.locator('label[for="regenerateExtensionsToggle"]').click();
        await expect(input).toBeDisabled();
    });

    test('only files with the listed extensions are regenerated', async ({ page, request }) => {
        const suffix = Date.now();
        const jpg = await upload(request, `keep-${suffix}.jpg`, 'image/jpeg', fs.readFileSync(fixture));
        const target = await upload(request, `target-${suffix}.qzx`, 'application/octet-stream', Buffer.from('regenerate me'));

        // The jpg has a finished preview that must survive a qzx-only regenerate
        expect((await request.post(`/api/preview/generate/${jpg.id}`)).ok()).toBeTruthy();

        await page.goto('/admin/settings');
        await page.locator('a.item[data-tab="preview-settings"]').click();
        await page.locator('label[for="regenerateExtensionsToggle"]').click();
        await page.locator('#regenerateExtensions').fill(' .QZX ');
        page.once('dialog', (dialog) => dialog.accept());
        await page.locator('#regenerateAllBtn').click();
        const list = await (await request.get('/files?limit=100&sort=date_newest')).json();
        // One job for every qzx file (earlier runs may have left some); none for the jpg
        const qzxCount = list.files.filter((f) => f.extension === 'qzx').length;
        await expect(page.locator('#previewActionResult')).toContainText(`Previews for qzx reset and requeued. Created ${qzxCount} preview jobs.`);
        expect(list.files.find((f) => f.id === jpg.id).preview_status).toBe('completed');
        expect(list.files.some((f) => f.id === target.id)).toBe(true);
    });

    test('rejects an invalid list and matches nothing for an unused extension', async ({ request }) => {
        const invalid = await regenerate(request, { action: 'regenerate_all', extensions: 'jp g' });
        expect(invalid.status()).toBe(400);
        expect((await invalid.json()).error).toContain('Invalid file extension');

        const blank = await regenerate(request, { action: 'regenerate_all', extensions: ' , ' });
        expect(blank.status()).toBe(400);

        const none = await regenerate(request, { action: 'regenerate_all', extensions: 'zzzz' });
        expect((await none.json()).message).toContain('Created 0 preview jobs');
    });
});
