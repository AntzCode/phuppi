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

test.describe('More options menu', () => {
    test('a three-dot button sits next to the search field and holds sort and page size', async ({ page, request }) => {
        await seed(request);
        await page.goto('/');

        const button = page.locator('#more-options-button');
        await expect(button.locator('i.ellipsis.vertical.icon')).toBeVisible();

        // Right beside the search field, on the same row
        const [searchBox, buttonBox] = await Promise.all([page.locator('#file-search').boundingBox(), button.boundingBox()]);
        expect(buttonBox.x).toBeGreaterThanOrEqual(searchBox.x + searchBox.width);
        expect(Math.abs(buttonBox.y - searchBox.y)).toBeLessThan(10);

        // Sort and page size are gone from the sticky list menu
        const listMenu = page.locator('#file-list-menu');
        await expect(listMenu.getByText('per page')).toHaveCount(0);
        await expect(listMenu.getByText('Date (newest)')).toHaveCount(0);

        await button.click();
        const menu = page.locator('#more-options-menu');
        await expect(menu.getByText('Sort by')).toBeVisible();
        await expect(menu.getByText('Rows per page')).toBeVisible();
        await expect(menu.locator('[data-sort]')).toHaveCount(6);
        await expect(menu.locator('[data-limit]')).toHaveCount(8);
        // The current choices are highlighted
        await expect(menu.locator('[data-sort="date_newest"]')).toHaveClass(/active/);
        await expect(menu.locator('[data-limit="10"]')).toHaveClass(/active/);
    });

    test('choosing a sort order re-sorts the list and closes the menu', async ({ page, request }) => {
        await seed(request);
        await page.goto('/');
        await expect(page.locator('#file-cards .file-card').first()).toBeVisible();
        const newest = await firstId(page);

        await page.locator('#more-options-button').click();
        await page.locator('#more-options-menu [data-sort="date_oldest"]').click();
        await expect(page.locator('#more-options-menu')).toHaveCount(0);
        await expect.poll(() => firstId(page)).not.toBe(newest);

        // And back again
        await page.locator('#more-options-button').click();
        await page.locator('#more-options-menu [data-sort="date_newest"]').click();
        await expect.poll(() => firstId(page)).toBe(newest);
    });

    test('choosing rows per page changes the page size', async ({ page, request }) => {
        await seed(request);
        await page.goto('/');
        await expect(page.getByText(/Your Uploaded Files 10 of/)).toBeVisible();

        await page.locator('#more-options-button').click();
        await page.locator('#more-options-menu [data-limit="5"]').click();
        await expect(page.getByText(/Your Uploaded Files 5 of/)).toBeVisible();
        await expect(page.locator('#file-cards .file-card')).toHaveCount(5);

        // Put the default back for the other specs
        await page.locator('#more-options-button').click();
        await page.locator('#more-options-menu [data-limit="10"]').click();
        await expect(page.getByText(/Your Uploaded Files 10 of/)).toBeVisible();
    });

    test('closes on an outside click and on Escape', async ({ page }) => {
        await page.goto('/');
        const menu = page.locator('#more-options-menu');

        await page.locator('#more-options-button').click();
        await expect(menu).toBeVisible();
        await page.locator('h2, h1').first().click();
        await expect(menu).toHaveCount(0);

        await page.locator('#more-options-button').click();
        await expect(menu).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(menu).toHaveCount(0);
    });

    test('on mobile the menu stays on screen', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 700 });
        await page.goto('/');
        await page.locator('#more-options-button').click();

        const box = await page.locator('#more-options-menu').boundingBox();
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(375);
    });
});
