import { test, expect } from '@playwright/test';
import { AUTH_FILE } from '../support/env.js';

test.use({ storageState: AUTH_FILE });

test.describe('Upload form', () => {
    test('is hidden on load and shown by the blue button', async ({ page }) => {
        await page.goto('/');

        const toggle = page.locator('#toggle-upload-form');
        await expect(toggle).toBeVisible();
        await expect(toggle).toHaveClass(/blue/);
        await expect(page.locator('#upload')).toBeHidden();

        await toggle.click();
        await expect(page.locator('#upload')).toBeVisible();
        await expect(toggle).toHaveText(/Hide Upload/);

        await toggle.click();
        await expect(page.locator('#upload')).toBeHidden();
    });

    test('starts hidden even when the stylesheet is stale or missing', async ({ page }) => {
        // A browser holding an old cached phuppi.css must not see the form on load
        await page.route('**/assets/css/phuppi.css*', (route) => route.fulfill({ status: 200, contentType: 'text/css', body: '' }));
        await page.goto('/');

        await expect(page.locator('#toggle-upload-form')).toBeVisible();
        await expect(page.locator('#upload')).toBeHidden();
        expect((await page.locator('.upload-form-collapse').boundingBox()).height).toBe(0);
    });

    test('slides into view instead of appearing at once', async ({ page }) => {
        await page.goto('/');

        // Click, then sample the form's height on every frame while it opens
        const heights = await page.evaluate(async () => {
            const collapse = document.querySelector('.upload-form-collapse');
            const samples = [collapse.getBoundingClientRect().height];
            document.querySelector('#toggle-upload-form').click();
            const end = performance.now() + 800;
            while (performance.now() < end) {
                await new Promise(requestAnimationFrame);
                samples.push(collapse.getBoundingClientRect().height);
            }
            return samples;
        });

        const final = heights.at(-1);
        expect(heights[0]).toBe(0);
        expect(final).toBeGreaterThan(100);
        // Some frames are part-way between closed and fully open
        expect(heights.some((h) => h > 0 && h < final)).toBe(true);
    });

    test('uploads a file chosen in the form', async ({ page }) => {
        const name = `form-upload-${Date.now()}.txt`;

        await page.goto('/');
        await page.locator('#toggle-upload-form').click();
        await page.locator('#upload').setInputFiles({ name, mimeType: 'text/plain', buffer: Buffer.from('uploaded from the form') });
        await page.locator('button.submit', { hasText: /upload/i }).click();

        await expect(page.locator('#file-cards .file-card').first()).toBeVisible({ timeout: 30_000 });
        await expect(page.getByText(name).first()).toBeVisible({ timeout: 30_000 });
    });
});
