import { test, expect } from '@playwright/test';
import { AUTH_FILE } from '../support/env.js';

test.use({ storageState: AUTH_FILE });

test.describe('File card select button', () => {
    test('a checkbox button next to Share selects and deselects the card', async ({ page, request }) => {
        const name = `select-${Date.now()}.txt`;
        await request.post('/files', {
            multipart: { file: { name, mimeType: 'text/plain', buffer: Buffer.from('x') } },
        });
        await page.goto('/');
        const card = page.locator('#file-cards > .file-card', { hasText: name });
        const toggle = card.locator('.select-toggle');
        await expect(toggle).toBeVisible();

        // At the end of the footer, after Share
        const [shareBox, toggleBox] = await Promise.all([card.getByRole('button', { name: 'Share' }).boundingBox(), toggle.boundingBox()]);
        // ...as a separate button, with a gap between them
        expect(toggleBox.x).toBeGreaterThanOrEqual(shareBox.x + shareBox.width + 4);

        await expect(card).not.toHaveClass(/selected/);
        await toggle.click();
        await expect(card).toHaveClass(/selected/);
        await expect(toggle.locator('i.check.square')).toBeVisible();
        await expect(page.getByText(/With 1 Selected/)).toBeVisible();

        await toggle.click();
        await expect(card).not.toHaveClass(/selected/);
        await expect(toggle.locator('i.square.outline')).toBeVisible();
    });
});
