import { test, expect } from '@playwright/test';
import { AUTH_FILE } from '../support/env.js';

test.use({ storageState: AUTH_FILE });

test.describe('File list selection', () => {
    test('shift+click selects every file between two checkboxes', async ({ page, request }) => {
        for (let i = 0; i < 6; i++) {
            const response = await request.post('/files', {
                multipart: { file: { name: `select-${i}.txt`, mimeType: 'text/plain', buffer: Buffer.from(`file ${i}`) } },
            });
            expect(response.ok()).toBeTruthy();
        }

        await page.goto('/');
        // Show enough rows for the range
        const checkboxes = page.locator('.uploaded-file i.square.icon');
        await expect(checkboxes.nth(5)).toBeVisible();

        const selectedLabel = page.locator('.bulk-actions > label');
        await checkboxes.nth(1).click();
        await expect(selectedLabel).toContainText('With 1 Selected');

        await checkboxes.nth(4).click({ modifiers: ['Shift'] });
        await expect(selectedLabel).toContainText('With 4 Selected');

        // A plain click afterwards toggles just that row and moves the anchor
        await checkboxes.nth(2).click();
        await expect(selectedLabel).toContainText('With 3 Selected');
    });
});
