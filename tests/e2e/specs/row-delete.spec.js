import { test, expect } from '@playwright/test';
import { AUTH_FILE } from '../support/env.js';

test.use({ storageState: AUTH_FILE });

test.describe('File list deleting', () => {
    test('cards have no delete button; Delete Selected removes a file', async ({ page, request }) => {
        const name = `delete-me-${Date.now()}.txt`;
        const upload = await request.post('/files', {
            multipart: { file: { name, mimeType: 'text/plain', buffer: Buffer.from('bye') } },
        });
        expect(upload.ok()).toBeTruthy();

        await page.goto('/');
        const card = page.locator('#file-cards > .file-card', { hasText: name });
        await expect(card).toBeVisible();

        // No per-row delete button any more
        await expect(page.locator('#file-cards button', { hasText: 'Delete' })).toHaveCount(0);

        // The batch delete still works
        page.once('dialog', (dialog) => dialog.accept());
        await card.locator('> .content:not(.extra)').click();
        await page.locator('#file-list-menu .bulk-actions').click();
        await page.locator('#file-list-menu .bulk-actions .item[data-value="delete"]').click();
        await expect(page.locator('#file-cards > .file-card', { hasText: name })).toHaveCount(0);
    });
});
