import { test, expect } from '@playwright/test';
import { AUTH_FILE } from '../support/env.js';

test.use({ storageState: AUTH_FILE });

test.describe('File list pagination', () => {
    test('is shown once, centered under the list at the end of the page', async ({ page, request }) => {
        // The default page size is 10, so 11 files guarantee a second page
        for (let i = 0; i < 11; i++) {
            const response = await request.post('/files', {
                multipart: { file: { name: `page-${Date.now()}-${i}.txt`, mimeType: 'text/plain', buffer: Buffer.from(`file ${i}`) } },
            });
            expect(response.ok()).toBeTruthy();
        }

        await page.goto('/');
        const menu = page.locator('.ui.pagination.menu');
        await expect(menu).toHaveCount(1);

        // At the end of the page the menu rests below the list; check it is not full width and is centered on it
        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        const list = page.locator('#file-cards');
        const [menuBox, listBox] = await Promise.all([menu.boundingBox(), list.boundingBox()]);
        expect(menuBox.y).toBeGreaterThan(listBox.y + listBox.height - 1);
        expect(menuBox.width).toBeLessThan(listBox.width - 40);
        const menuCenter = menuBox.x + menuBox.width / 2;
        const listCenter = listBox.x + listBox.width / 2;
        expect(Math.abs(menuCenter - listCenter)).toBeLessThan(4);

        // The menu works: Next moves to page 2
        await menu.getByText('Next').click();
        await expect(menu.locator('.active.item')).toHaveText('2');
    });

    test('floats at the bottom of the screen without covering the last item', async ({ page, request }) => {
        await page.setViewportSize({ width: 1000, height: 500 });
        for (let i = 0; i < 11; i++) {
            await request.post('/files', {
                multipart: { file: { name: `float-${Date.now()}-${i}.txt`, mimeType: 'text/plain', buffer: Buffer.from(`file ${i}`) } },
            });
        }

        await page.goto('/');
        const bar = page.locator('.pagination-bar');
        const items = page.locator('#file-cards > .file-card');
        await expect(bar).toBeVisible();
        await expect(items.last()).toBeAttached();

        // At the top of a long list the bar is already on screen, pinned to the bottom edge
        const lastItemBox = await items.last().boundingBox();
        expect(lastItemBox.y).toBeGreaterThan(500);
        const floating = await bar.boundingBox();
        expect(floating.y + floating.height).toBeGreaterThan(498);
        expect(floating.y + floating.height).toBeLessThanOrEqual(500);

        // At the end of the page it sits below the last item instead of covering it
        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await expect.poll(async () => (await items.last().boundingBox()).y).toBeLessThan(500);
        const [lastItem, barAtEnd] = await Promise.all([items.last().boundingBox(), bar.boundingBox()]);
        expect(barAtEnd.y).toBeGreaterThanOrEqual(lastItem.y + lastItem.height - 1);
    });
});
