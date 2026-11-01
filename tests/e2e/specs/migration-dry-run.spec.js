import { test, expect } from '@playwright/test';
import { AUTH_FILE } from '../support/env.js';

test.use({ storageState: AUTH_FILE });

test.describe('Storage migration dry run', () => {
    test('reports what would be migrated without prompting or copying', async ({ page, request }) => {
        const response = await request.post('/files', {
            multipart: { file: { name: `dry-run-${Date.now()}.txt`, mimeType: 'text/plain', buffer: Buffer.from('dry run') } },
        });
        expect(response.ok()).toBeTruthy();

        // A real migration would prompt for confirmation; a dry run must not
        page.on('dialog', (dialog) => {
            throw new Error(`Unexpected dialog: ${dialog.message()}`);
        });

        await page.goto('/admin/settings');
        await page.locator('a.item[data-tab="migration"]').click();
        await page.locator('#dryRunCheckbox').check({ force: true });
        await page.locator('#startMigrationBtn').click();

        const label = page.locator('#progressLabel');
        await expect(label).toContainText('Dry run completed (no files were copied)!');
        // Default From and To are the same connector, so every file is skipped and nothing is copied
        await expect(label).toContainText('Would migrate: 0');
    });
});
