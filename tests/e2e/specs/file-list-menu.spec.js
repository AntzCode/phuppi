import { test, expect } from '@playwright/test';
import { AUTH_FILE } from '../support/env.js';

test.use({ storageState: AUTH_FILE });

test.describe('File list menu', () => {
    test('sticks to the top of the screen while the list is scrolled', async ({ page, request }) => {
        await page.setViewportSize({ width: 1100, height: 500 });
        for (let i = 0; i < 11; i++) {
            await request.post('/files', {
                multipart: { file: { name: `menu-${Date.now()}-${i}.txt`, mimeType: 'text/plain', buffer: Buffer.from(`file ${i}`) } },
            });
        }

        await page.goto('/');
        const menu = page.locator('#file-list-menu');

        // Hidden until something is selected
        await expect(page.locator('#file-cards .file-card').first()).toBeVisible();
        await expect(menu).toHaveCount(0);
        await page.locator('#file-cards .file-card .select-toggle').first().click();
        await expect(menu).toBeVisible();
        await page.evaluate(() => window.scrollTo(0, 0));

        // At the top of the page it sits in its normal place, below the page header
        expect((await menu.boundingBox()).y).toBeGreaterThan(100);

        // Scrolled down the list, it stays pinned at the top edge with its controls usable
        await page.evaluate(() => window.scrollTo(0, 700));
        await expect.poll(async () => Math.round((await menu.boundingBox()).y)).toBe(0);
        await expect(menu.getByText('Select All')).toBeVisible();

        // And it still works from there
        await menu.getByText('Select All').click();
        await expect(menu.getByText(/With 10 Selected/)).toBeVisible();

        // Deselecting everything hides it again
        await menu.getByText('Select All').click();
        await expect(menu).toHaveCount(0);
    });

    test('search is on the left of the bar, level with the upload button, and still filters', async ({ page, request }) => {
        const name = `needle-${Date.now()}.txt`;
        await request.post('/files', {
            multipart: { file: { name, mimeType: 'text/plain', buffer: Buffer.from('needle') } },
        });

        await page.goto('/');
        const search = page.getByPlaceholder('Search...');
        await expect(search).toHaveCount(1);
        // Not inside the sticky menu any more
        await expect(page.locator('#file-list-menu').getByPlaceholder('Search...')).toHaveCount(0);

        // Same row as the upload button, on the far left of the content area
        const [searchBox, buttonBox, menuBox] = await Promise.all([
            page.locator('#file-search').boundingBox(),
            page.locator('#toggle-upload-form').boundingBox(),
            page.locator('#file-search-bar').boundingBox(),
        ]);
        expect(Math.abs(searchBox.y - buttonBox.y)).toBeLessThan(20);
        expect(searchBox.x).toBeLessThan(menuBox.x + 30);
        expect(searchBox.x).toBeLessThan(buttonBox.x);

        // It still searches
        await search.fill(name);
        await search.press('Enter');
        await expect(page.getByText(name).first()).toBeVisible();
        await expect(page.getByText('Your Uploaded Files 1 of 1')).toBeVisible();
    });
});
