import { test, expect } from '@playwright/test';
import { AUTH_FILE } from '../support/env.js';

test.use({ storageState: AUTH_FILE });

async function seed(request, count = 12) {
    const names = [];
    for (let i = 0; i < count; i++) {
        const name = `card-${Date.now()}-${i}.txt`;
        names.push(name);
        await request.post('/files', {
            multipart: { file: { name, mimeType: 'text/plain', buffer: Buffer.from(`file ${i}`) } },
        });
    }
    return names;
}

/** How many cards share the first card's row */
async function cardsPerRow(page) {
    const boxes = await page.locator('#file-cards > .file-card').evaluateAll(
        (cards) => cards.map((c) => { const r = c.getBoundingClientRect(); return { x: r.x, y: Math.round(r.y) }; }),
    );
    return boxes.filter((b) => b.y === boxes[0].y).length;
}

test.describe('File cards', () => {
    test('three across on desktop, two on tablets, one on phones', async ({ page, request }) => {
        await seed(request);
        for (const [width, expected] of [[1200, 3], [900, 2], [600, 1]]) {
            await page.setViewportSize({ width, height: 800 });
            await page.goto('/');
            await expect(page.locator('#file-cards > .file-card').first()).toBeVisible();
            expect(await cardsPerRow(page), `${width}px wide`).toBe(expected);
        }

        // And it follows a resize without a reload
        await page.setViewportSize({ width: 1200, height: 800 });
        await expect.poll(() => cardsPerRow(page)).toBe(3);
    });

    test('each card has a footer with Download and Share', async ({ page, request }) => {
        await seed(request, 3);
        await page.goto('/');
        const card = page.locator('#file-cards > .file-card').first();

        const footer = card.locator('.extra.content');
        await expect(footer.getByRole('link', { name: 'Download' })).toBeVisible();
        await expect(footer.getByRole('button', { name: 'Share' })).toBeVisible();

        // The footer sits at the bottom of the card, below the text
        const [cardBox, footerBox, contentBox] = await Promise.all([card.boundingBox(), footer.boundingBox(), card.locator('> .content:not(.extra)').boundingBox()]);
        expect(footerBox.y).toBeGreaterThanOrEqual(contentBox.y + contentBox.height - 1);
        expect(footerBox.y + footerBox.height).toBeGreaterThan(cardBox.y + cardBox.height - 6);
    });

    test('a selected card has a blue border and shadow, and there is no checkbox', async ({ page, request }) => {
        await seed(request, 3);
        await page.goto('/');
        const cards = page.locator('#file-cards > .file-card');
        await expect(cards.first()).toBeVisible();
        await expect(page.locator('#file-cards i.square.icon')).toHaveCount(0);

        const card = cards.nth(1);
        const style = () => card.evaluate((el) => { const c = getComputedStyle(el); return { border: c.borderTopColor, shadow: c.boxShadow }; });

        const before = await style();
        expect(before.border).not.toBe('rgb(33, 133, 208)');

        await card.locator('> .content:not(.extra)').click();
        await expect(card).toHaveAttribute('aria-pressed', 'true');
        await expect.poll(async () => (await style()).border).toBe('rgb(33, 133, 208)');
        expect((await style()).shadow).toContain('rgba(33, 133, 208');
        await expect(page.locator('.bulk-actions > label')).toContainText('With 1 Selected');

        // Clicking again deselects
        await card.locator('> .content:not(.extra)').click();
        await expect(card).toHaveAttribute('aria-pressed', 'false');
        await expect.poll(async () => (await style()).border).not.toBe('rgb(33, 133, 208)');
    });

    test('shift+click selects the cards in between', async ({ page, request }) => {
        await seed(request, 6);
        await page.goto('/');
        const cards = page.locator('#file-cards > .file-card');
        await expect(cards.nth(5)).toBeVisible();

        await cards.nth(1).locator('> .content:not(.extra)').click();
        await cards.nth(4).locator('> .content:not(.extra)').click({ modifiers: ['Shift'] });
        for (const i of [1, 2, 3, 4]) {
            await expect(cards.nth(i)).toHaveAttribute('aria-pressed', 'true');
        }
        await expect(cards.nth(0)).toHaveAttribute('aria-pressed', 'false');
        await expect(cards.nth(5)).toHaveAttribute('aria-pressed', 'false');
        await expect(page.locator('.bulk-actions > label')).toContainText('With 4 Selected');
    });

    test('the footer buttons and the preview thumbnail do not change the selection', async ({ page, request }) => {
        await seed(request, 3);
        await page.goto('/');
        const card = page.locator('#file-cards > .file-card').first();
        await expect(card).toBeVisible();

        // Share opens its dialog without selecting the card
        await card.getByRole('button', { name: 'Share' }).click();
        await expect(page.getByText('Generate Link')).toBeVisible();
        await page.getByRole('button', { name: 'Cancel' }).click();
        await expect(card).toHaveAttribute('aria-pressed', 'false');

        // The thumbnail opens the preview, also without selecting
        await card.locator('.image').click();
        await expect(page.locator('.modal .header i.close.icon')).toBeVisible();
        await page.locator('.modal .header i.close.icon').click();
        await expect(page.locator('.modal')).toHaveCount(0);
        await expect(card).toHaveAttribute('aria-pressed', 'false');
    });

    test('the preview has a close icon in the header, and Download and Share in the footer', async ({ page, request }) => {
        await seed(request, 3);
        await page.goto('/');
        const card = page.locator('#file-cards > .file-card').first();
        const id = await card.getAttribute('data-file-id');
        await card.locator('.image').click();

        const modal = page.locator('.modal');
        await expect(modal).toBeVisible();

        // The close icon sits at the top right of the header, like the share dialog's
        const [modalBox, closeBox] = await Promise.all([modal.boundingBox(), modal.locator('.header i.close.icon').boundingBox()]);
        expect(closeBox.x).toBeGreaterThan(modalBox.x + modalBox.width / 2);
        expect(closeBox.y).toBeLessThan(modalBox.y + 60);

        // The header also shows the file ID, size and upload date, as the card does
        const cardMeta = await card.locator('.meta').innerText();
        const date = cardMeta.match(/Uploaded at (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/)[1];
        const size = cardMeta.match(/([\d.]+ (?:bytes|KB|MB|GB))/)[1];
        await expect(modal.locator('#preview-meta')).toContainText(`ID ${id}`);
        await expect(modal.locator('#preview-meta')).toContainText(size);
        await expect(modal.locator('#preview-meta')).toContainText(`Uploaded at ${date}`);

        // No Close button in the footer; Download and Share instead
        const footer = modal.locator('.actions');
        await expect(footer.getByRole('button', { name: 'Close' })).toHaveCount(0);
        await expect(footer.getByRole('link', { name: 'Download' })).toHaveAttribute('href', `/files/${id}?download=true`);

        // Share closes the preview and opens the share dialog
        await footer.getByRole('button', { name: 'Share' }).click();
        await expect(page.getByText('Generate Link')).toBeVisible();
        await expect(page.locator('.modal .header', { hasText: 'Share' })).toBeVisible();
        await expect(page.locator('.modal')).toHaveCount(1);
    });

    test('a card can be selected from the keyboard', async ({ page, request }) => {
        await seed(request, 3);
        await page.goto('/');
        const card = page.locator('#file-cards > .file-card').first();
        await card.focus();
        await page.keyboard.press('Enter');
        await expect(card).toHaveAttribute('aria-pressed', 'true');
        await page.keyboard.press('Space');
        await expect(card).toHaveAttribute('aria-pressed', 'false');
    });
});
