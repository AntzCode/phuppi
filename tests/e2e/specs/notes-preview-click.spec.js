import { test, expect } from '@playwright/test';
import { AUTH_FILE } from '../support/env.js';

test.use({ storageState: AUTH_FILE });

test.describe('Notes preview', () => {
    let name;
    let card;

    test.beforeEach(async ({ page, request }) => {
        name = `preview-click-${Date.now()}`;
        const created = await request.post('/notes', {
            headers: { 'Content-Type': 'application/json' },
            data: { filename: name, content: 'alpha bravo charlie delta' },
        });
        expect(created.ok()).toBeTruthy();
        await page.goto('/notes');
        // Other specs leave many notes behind, so find this one by searching for it
        await page.getByPlaceholder('Search...').fill(name);
        await page.getByPlaceholder('Search...').press('Enter');
        card = page.locator('.card', { hasText: name });
        await expect(card).toBeVisible();
    });

    test('has no View button; clicking the preview text opens the note', async ({ page }) => {
        await expect(card.locator('button', { hasText: 'View' })).toHaveCount(0);
        await card.locator('.description').click();
        await expect(page.locator('.ui.modal .header', { hasText: name })).toBeVisible();
    });

    test('selecting the preview text does not open the note', async ({ page }) => {
        await expect(card.locator('.description')).toBeVisible();
        const box = await card.locator('.description').boundingBox();
        await page.mouse.move(box.x + 1, box.y + 5);
        await page.mouse.down();
        await page.mouse.move(box.x + 80, box.y + 5, { steps: 5 });
        await page.mouse.up();
        const selected = await page.evaluate(() => window.getSelection().toString());
        expect(selected.length).toBeGreaterThan(0);
        await page.waitForTimeout(500);
        await expect(page.locator('.ui.modal .header', { hasText: name })).toHaveCount(0);
    });

    test('double clicking a word selects it without opening the note', async ({ page }) => {
        await card.locator('.description').dblclick({ position: { x: 10, y: 8 } });
        await page.waitForTimeout(500);
        expect(await page.evaluate(() => window.getSelection().toString().trim())).not.toBe('');
        await expect(page.locator('.ui.modal .header', { hasText: name })).toHaveCount(0);
    });
});

test.describe('Notes preview wrapping', () => {
    test('long unbroken text wraps inside the card', async ({ page, request }) => {
        const name = `preview-wrap-${Date.now()}`;
        const created = await request.post('/notes', {
            headers: { 'Content-Type': 'application/json' },
            data: { filename: name, content: 'x'.repeat(140) },
        });
        expect(created.ok()).toBeTruthy();
        await page.goto('/notes');
        const card = page.locator('.card', { hasText: name });
        const text = card.locator('.description');
        await expect(text).toBeVisible();
        const [cardBox, textBox] = [await card.boundingBox(), await text.boundingBox()];
        expect(textBox.x + textBox.width).toBeLessThanOrEqual(cardBox.x + cardBox.width + 1);
        expect(await text.evaluate((el) => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    });
});

test.describe('Notes create button', () => {
    test('is a blue button at the right end of the search bar and opens the create dialog', async ({ page }) => {
        await page.goto('/notes');
        const button = page.locator('#create-note');
        await expect(button).toBeVisible();
        await expect(button).toHaveClass(/blue/);
        const [buttonBox, barBox] = [await button.boundingBox(), await page.locator('.ui.stackable.menu').first().boundingBox()];
        expect(buttonBox.x + buttonBox.width).toBeGreaterThan(barBox.x + barBox.width - 40);
        expect(buttonBox.y).toBeGreaterThanOrEqual(barBox.y);
        expect(buttonBox.y + buttonBox.height).toBeLessThanOrEqual(barBox.y + barBox.height);
        await button.click();
        await expect(page.locator('.ui.modal .header', { hasText: 'Create Note' })).toBeVisible();
    });
});

test.describe('Notes card buttons', () => {
    test('Edit and Share are attached to each other', async ({ page, request }) => {
        const name = `buttons-${Date.now()}`;
        await request.post('/notes', {
            headers: { 'Content-Type': 'application/json' },
            data: { filename: name, content: 'attached' },
        });
        await page.goto('/notes');
        await page.getByPlaceholder('Search...').fill(name);
        await page.getByPlaceholder('Search...').press('Enter');
        const card = page.locator('.card', { hasText: name });
        const [edit, share] = [card.getByRole('button', { name: 'Edit' }), card.getByRole('button', { name: 'Share' })];
        await expect(edit).toBeVisible();
        const [editBox, shareBox] = [await edit.boundingBox(), await share.boundingBox()];
        expect(Math.abs(shareBox.x - (editBox.x + editBox.width))).toBeLessThanOrEqual(1);
    });
});

test.describe('Notes view dialog', () => {
    test('fits inside the viewport on a phone, with its buttons and close icon reachable', async ({ page, request }) => {
        const name = `lightbox-${Date.now()}`;
        await request.post('/notes', {
            headers: { 'Content-Type': 'application/json' },
            data: { filename: name, content: ('a long line of note text '.repeat(10) + '\n').repeat(60) + 'U'.repeat(200) },
        });
        await page.setViewportSize({ width: 414, height: 700 });
        await page.goto('/notes');
        await page.getByPlaceholder('Search...').fill(name);
        await page.getByPlaceholder('Search...').press('Enter');
        await page.locator('.card', { hasText: name }).locator('.description').click();

        const modal = page.locator('#note-view-modal');
        await expect(modal).toBeVisible();
        await expect.poll(async () => { const b = await modal.boundingBox(); return b.y >= 0 && b.y + b.height <= 700 && b.x >= 0 && b.x + b.width <= 414; }).toBe(true);
        await expect(modal.getByRole('button', { name: 'Copy' })).toBeInViewport();
        await expect(modal.getByRole('button', { name: 'View Raw' })).toBeInViewport();
        await modal.locator('.header .close.icon').click();
        await expect(modal).toHaveCount(0);
    });
});

test.describe('Notes dialog headers', () => {
    test('the close icon stays level with the title when the note name is long', async ({ page, request }) => {
        const name = `long-title-${Date.now()}-` + 'morrinsville shopping list '.repeat(3);
        await request.post('/notes', { headers: { 'Content-Type': 'application/json' }, data: { filename: name, content: 'x' } });
        await page.setViewportSize({ width: 400, height: 800 });
        await page.goto('/notes');
        await page.getByPlaceholder('Search...').fill(name.slice(0, 30));
        await page.getByPlaceholder('Search...').press('Enter');
        const card = page.locator('.card', { hasText: name.slice(0, 30) }).first();

        await card.getByRole('button', { name: 'Share' }).click();
        const share = page.locator('.ui.modal', { hasText: 'Link Validity Duration' });
        const [title, icon] = [await share.locator('.header span').boundingBox(), await share.locator('.header .close.icon').boundingBox()];
        expect(title.height).toBeGreaterThan(30); // the title really wraps onto several lines
        expect(icon.y).toBeLessThan(title.y + 30); // and the icon is beside the first line, not under the last
        await share.locator('.header .close.icon').click();

        await card.locator('.description').click();
        const view = page.locator('#note-view-modal');
        const [vTitle, vIcon] = [await view.locator('.header span').boundingBox(), await view.locator('.header .close.icon').boundingBox()];
        expect(vIcon.y).toBeLessThan(vTitle.y + 30);
    });
});
