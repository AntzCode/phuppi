import { test, expect } from '@playwright/test';
import { AUTH_FILE } from '../support/env.js';

test.use({ storageState: AUTH_FILE });

test.describe('Navigation', () => {
    for (const link of ['P2P Share', 'Receive Files']) {
        test(`"${link}" opens a rendered page`, async ({ page }) => {
            await page.goto('/');
            await page.getByRole('link', { name: link }).click();
            await page.waitForLoadState('load');

            await expect(page.locator('h1').first()).toBeVisible();
        });
    }

    test('old /p2p link redirects to the share page', async ({ page }) => {
        await page.goto('/p2p');
        await expect(page).toHaveURL(/\/p2p\/share$/);
        await expect(page.locator('h1').first()).toBeVisible();
    });
});
