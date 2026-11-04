import { test, expect } from '@playwright/test';
import { AUTH_FILE } from '../support/env.js';

test.use({ storageState: AUTH_FILE });

test.describe('Notes pagination', () => {
    test('floats at the bottom of the screen without covering the last note', async ({ page, request }) => {
        await page.setViewportSize({ width: 1000, height: 500 });
        // The default page size is 10, so 11 notes guarantee a second page
        for (let i = 0; i < 11; i++) {
            const response = await request.post('/notes', {
                headers: { 'Content-Type': 'application/json' },
                data: { filename: `float-${Date.now()}-${i}`, content: `note ${i}` },
            });
            expect(response.ok()).toBeTruthy();
        }

        await page.goto('/notes');
        const bar = page.locator('.pagination-bar');
        const menu = page.locator('.ui.pagination.menu');
        const cards = page.locator('.cards > .card');
        await expect(menu).toHaveCount(1);
        await expect(bar).toBeVisible();
        await expect(cards.last()).toBeAttached();

        // At the top of a long list the bar is already on screen, pinned to the bottom edge
        expect((await cards.last().boundingBox()).y).toBeGreaterThan(500);
        const floating = await bar.boundingBox();
        expect(floating.y + floating.height).toBeGreaterThan(498);
        expect(floating.y + floating.height).toBeLessThanOrEqual(500);

        // At the end of the page it sits below the last card instead of covering it
        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await expect.poll(async () => (await cards.last().boundingBox()).y).toBeLessThan(500);
        const [lastCard, barAtEnd] = await Promise.all([cards.last().boundingBox(), bar.boundingBox()]);
        expect(barAtEnd.y).toBeGreaterThanOrEqual(lastCard.y + lastCard.height - 1);

        // The menu works: Next moves to page 2
        await menu.getByText('Next').click();
        await expect(menu.locator('.active.item')).toHaveText('2');
    });
});

test.describe('Pagination on a phone', () => {
    test('a page list of several pages does not make the page scroll sideways', async ({ page }) => {
        // Pretend there are 7 pages: up to 10 pages the bar lists every page number, which is wider than a phone
        await page.route('**/notes/list*', async (route) => {
            const response = await route.fetch();
            const body = await response.json();
            await route.fulfill({ response, json: { ...body, total: 70 } });
        });
        await page.setViewportSize({ width: 390, height: 700 });
        await page.goto('/notes');
        const menu = page.locator('.ui.pagination.menu');
        await expect(menu.locator('.item', { hasText: /^7$/ })).toBeAttached();

        const widths = await page.evaluate(() => ({ doc: document.documentElement.scrollWidth, win: innerWidth }));
        expect(widths.doc).toBeLessThanOrEqual(widths.win);
        expect((await menu.boundingBox()).width).toBeLessThanOrEqual(390);
    });
});
