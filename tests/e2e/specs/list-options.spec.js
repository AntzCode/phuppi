import { test, expect } from '@playwright/test';
import { AUTH_FILE } from '../support/env.js';

test.use({ storageState: AUTH_FILE });

async function seed(request, count = 12) {
    for (let i = 0; i < count; i++) {
        await request.post('/files', {
            multipart: { file: { name: `opts-${Date.now()}-${i}.txt`, mimeType: 'text/plain', buffer: Buffer.from(`file ${i}`) } },
        });
    }
}

const firstId = (page) => page.locator('#file-cards .file-card .header span').first().innerText();

test.describe('File list options bar', () => {
    test('search, sort and rows per page sit together on the left, with the upload button at the right end', async ({ page, request }) => {
        await seed(request);
        await page.goto('/');

        // The three-dot button is gone
        await expect(page.locator('#more-options-button')).toHaveCount(0);

        const [searchBox, sortBox, limitBox, uploadBox, barBox] = await Promise.all([
            page.locator('#file-search').boundingBox(),
            page.locator('#file-sort').boundingBox(),
            page.locator('#file-limit').boundingBox(),
            page.locator('#toggle-upload-form').boundingBox(),
            page.locator('#file-search-bar').boundingBox(),
        ]);
        expect(searchBox.x).toBeLessThan(sortBox.x);
        expect(sortBox.x).toBeLessThan(limitBox.x);
        expect(limitBox.x + limitBox.width).toBeLessThan(uploadBox.x);
        expect(uploadBox.x + uploadBox.width).toBeGreaterThan(barBox.x + barBox.width - 40);
        expect(Math.abs(sortBox.y - searchBox.y)).toBeLessThan(20);

        // Sort and page size are not in the sticky list menu
        const listMenu = page.locator('#file-list-menu');
        await expect(listMenu.getByText('per page')).toHaveCount(0);
        await expect(listMenu.getByText('Date (newest)')).toHaveCount(0);

        await expect(page.locator('#file-sort label')).toHaveText('Date (newest)');
        await expect(page.locator('#file-limit label')).toHaveText('10 per page');
        await page.locator('#file-sort').click();
        await expect(page.locator('#file-sort .menu .item')).toHaveCount(6);
        await page.keyboard.press('Escape');
        await page.locator('#file-limit').click();
        await expect(page.locator('#file-limit .menu .item')).toHaveCount(8);
    });

    test('choosing a sort order re-sorts the list', async ({ page, request }) => {
        await seed(request);
        await page.goto('/');
        await expect(page.locator('#file-cards .file-card').first()).toBeVisible();
        const newest = await firstId(page);

        await page.locator('#file-sort').click();
        await page.locator('#file-sort .item[data-value="date_oldest"]').click();
        await expect(page.locator('#file-sort label')).toHaveText('Date (oldest)');
        await expect.poll(() => firstId(page)).not.toBe(newest);

        // And back again
        await page.locator('#file-sort').click();
        await page.locator('#file-sort .item[data-value="date_newest"]').click();
        await expect.poll(() => firstId(page)).toBe(newest);
    });

    test('choosing rows per page changes the page size', async ({ page, request }) => {
        await seed(request);
        await page.goto('/');
        await expect(page.getByText(/Your Uploaded Files 10 of/)).toBeVisible();

        await page.locator('#file-limit').click();
        await page.locator('#file-limit .item[data-value="5"]').click();
        await expect(page.getByText(/Your Uploaded Files 5 of/)).toBeVisible();
        await expect(page.locator('#file-cards .file-card')).toHaveCount(5);
        await expect(page.locator('#file-limit label')).toHaveText('5 per page');

        // Put the default back for the other specs
        await page.locator('#file-limit').click();
        await page.locator('#file-limit .item[data-value="10"]').click();
        await expect(page.getByText(/Your Uploaded Files 10 of/)).toBeVisible();
    });

    test('on mobile the bar stacks into one column with its contents aligned right', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 700 });
        await page.goto('/');

        const [sortBox, limitBox, uploadBox, barBox] = await Promise.all([
            page.locator('#file-sort').boundingBox(),
            page.locator('#file-limit').boundingBox(),
            page.locator('#toggle-upload-form').boundingBox(),
            page.locator('#file-search-bar').boundingBox(),
        ]);
        expect(limitBox.y).toBeGreaterThan(sortBox.y);
        expect(uploadBox.y).toBeGreaterThan(limitBox.y);
        expect(uploadBox.x + uploadBox.width).toBeGreaterThan(barBox.x + barBox.width - 30);
        expect(await page.locator('#file-sort').evaluate((el) => getComputedStyle(el).justifyContent)).toBe('flex-end');
    });
});
