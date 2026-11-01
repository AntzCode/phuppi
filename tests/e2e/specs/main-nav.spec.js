import { test, expect } from '@playwright/test';
import { AUTH_FILE } from '../support/env.js';

test.use({ storageState: AUTH_FILE });

const nav = (page) => page.locator('nav.main-nav');
// Match on the link's own text (icons add glyph characters to the accessible name)
const link = (page, name) => nav(page).locator('a').filter({ hasText: new RegExp(`^\\s*${name}\\s*$`) });

test.describe('Main navigation', () => {
    test('shows the everyday links, plus Admin and Account menus', async ({ page }) => {
        await page.setViewportSize({ width: 1200, height: 800 });
        await page.goto('/');

        for (const name of ['Files', 'Notes', 'P2P Share', 'Receive Files']) {
            await expect(link(page, name)).toBeVisible();
        }
        await expect(nav(page).getByRole('button', { name: /Admin/ })).toBeVisible();
        await expect(nav(page).getByRole('button', { name: /Account/ })).toBeVisible();

        // Everything else lives in the menus, not on the bar
        for (const name of ['Settings', 'Users', 'Vouchers', 'Profile', 'Short Links', 'Log Out', 'Duplicates']) {
            await expect(link(page, name)).toBeHidden();
        }

        // One tidy row on desktop
        const tops = await nav(page).locator('.nav-list > li > .nav-link').evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().y)));
        expect(new Set(tops).size).toBe(1);
    });

    test('the Admin and Account menus open, list their pages and close again', async ({ page }) => {
        await page.setViewportSize({ width: 1200, height: 800 });
        await page.goto('/');

        const admin = nav(page).getByRole('button', { name: /Admin/ });
        await admin.click();
        await expect(admin).toHaveAttribute('aria-expanded', 'true');
        for (const name of ['Settings', 'Users', 'Vouchers']) {
            await expect(link(page, name)).toBeVisible();
        }
        // Duplicates is no longer in the menu
        await expect(link(page, 'Duplicates')).toHaveCount(0);

        // Opening Account closes Admin
        const account = nav(page).getByRole('button', { name: /Account/ });
        await account.click();
        await expect(admin).toHaveAttribute('aria-expanded', 'false');
        for (const name of ['Profile', 'Short Links', 'Log Out']) {
            await expect(link(page, name)).toBeVisible();
        }

        // Outside click and Escape close it
        await page.locator('h2, h1').first().click();
        await expect(link(page, 'Profile')).toBeHidden();
        await account.click();
        await expect(link(page, 'Profile')).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(link(page, 'Profile')).toBeHidden();
    });

    test('highlights the current page, and its menu when the page is inside one', async ({ page }) => {
        await page.setViewportSize({ width: 1200, height: 800 });

        await page.goto('/');
        await expect(link(page, 'Files')).toHaveClass(/active/);
        await expect(link(page, 'Files')).toHaveAttribute('aria-current', 'page');
        await expect(link(page, 'Notes')).not.toHaveClass(/active/);

        await page.goto('/p2p/receive');
        await expect(link(page, 'Receive Files')).toHaveClass(/active/);
        await expect(link(page, 'P2P Share')).not.toHaveClass(/active/);

        await page.goto('/users/settings');
        await expect(nav(page).getByRole('button', { name: /Account/ })).toHaveClass(/active/);
        await expect(nav(page).getByRole('button', { name: /Admin/ })).not.toHaveClass(/active/);
        await nav(page).getByRole('button', { name: /Account/ }).click();
        await expect(link(page, 'Profile')).toHaveClass(/active/);

        await page.goto('/admin/settings');
        await expect(nav(page).getByRole('button', { name: /Admin/ })).toHaveClass(/active/);
    });

    test('Duplicates is reached from the Settings page', async ({ page }) => {
        await page.goto('/admin/settings');
        await page.locator('#duplicates-link').click();
        await expect(page).toHaveURL(/\/duplicates$/);
        // The Admin menu stays highlighted there
        await expect(nav(page).getByRole('button', { name: /Admin/ })).toHaveClass(/active/);
    });

    test('Log Out is in the Account menu', async ({ page }) => {
        // Not clicked: logging out ends the admin session that the other specs share
        await page.goto('/');
        await expect(link(page, 'Log Out')).toBeHidden();
        await nav(page).getByRole('button', { name: /Account/ }).click();
        await expect(link(page, 'Log Out')).toBeVisible();
        await expect(link(page, 'Log Out')).toHaveAttribute('href', '/logout');
    });

    test('on a phone the menu collapses behind a Menu button', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 800 });
        await page.goto('/');

        const toggle = nav(page).getByRole('button', { name: 'Menu' });
        await expect(toggle).toBeVisible();
        await expect(link(page, 'Files')).toBeHidden();

        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        for (const name of ['Files', 'Notes', 'P2P Share', 'Receive Files']) {
            await expect(link(page, name)).toBeVisible();
        }

        // Account expands inline, inside the screen
        await nav(page).getByRole('button', { name: /Account/ }).click();
        const logout = await link(page, 'Log Out').boundingBox();
        expect(logout.x).toBeGreaterThanOrEqual(0);
        expect(logout.x + logout.width).toBeLessThanOrEqual(375);

        // And on desktop the Menu button is not shown
        await page.setViewportSize({ width: 1200, height: 800 });
        await expect(toggle).toBeHidden();
    });

    test('uses the brown palette: square corners, an underline on the selected item', async ({ page }) => {
        await page.setViewportSize({ width: 1200, height: 800 });
        await page.goto('/');

        const style = (locator) => locator.evaluate((el) => {
            const c = getComputedStyle(el);
            return { color: c.color, radius: c.borderTopLeftRadius, shadow: c.boxShadow, bottom: c.borderBottomWidth + ' ' + c.borderBottomStyle + ' ' + c.borderBottomColor };
        });

        // Main items and the selected item: #504024
        const notes = await style(link(page, 'Notes'));
        expect(notes.color).toBe('rgb(80, 64, 36)');
        expect(notes.radius).toBe('0px');

        // Selected: #504024 text with a 2px solid #866f46 bottom border instead of a shadow
        const files = await style(link(page, 'Files'));
        expect(files.color).toBe('rgb(80, 64, 36)');
        expect(files.radius).toBe('0px');
        expect(files.shadow).toBe('none');
        expect(files.bottom).toBe('2px solid rgb(134, 111, 70)');

        // The dropdown buttons share the main item colour
        const admin = await style(nav(page).getByRole('button', { name: /Admin/ }));
        expect(admin.color).toBe('rgb(80, 64, 36)');
        expect(admin.radius).toBe('0px');

        // Submenu items: #251f15, square, with the same underline on the selected one
        await page.goto('/users/settings');
        await nav(page).getByRole('button', { name: /Account/ }).click();
        const shortLinks = await style(link(page, 'Short Links'));
        expect(shortLinks.color).toBe('rgb(37, 31, 21)');
        const profile = await style(link(page, 'Profile'));
        expect(profile.color).toBe('rgb(37, 31, 21)');
        expect(profile.shadow).toBe('none');
        expect(profile.bottom).toBe('2px solid rgb(134, 111, 70)');
        const panel = await nav(page).locator('.nav-dropdown.open .nav-dropdown-menu').evaluate((el) => getComputedStyle(el).borderTopLeftRadius);
        expect(panel).toBe('0px');
    });
});
