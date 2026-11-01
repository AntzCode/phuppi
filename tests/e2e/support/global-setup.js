import { chromium } from '@playwright/test';
import { ADMIN, APP_URL, AUTH_FILE } from './env.js';

async function waitForApp(timeoutMs = 60_000) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        try {
            await fetch(APP_URL, { redirect: 'manual' });
            return;
        } catch {
            await new Promise((resolve) => setTimeout(resolve, 1000));
        }
    }
    throw new Error(`App not reachable at ${APP_URL}. Start it with: npm run stack:up`);
}

/**
 * Installs Phuppi on a fresh database (or logs in to an existing one)
 * and saves the admin session for the tests to reuse.
 */
export default async function globalSetup() {
    await waitForApp();

    const browser = await chromium.launch();
    const page = await browser.newPage({ baseURL: APP_URL });

    await page.goto('/');

    const installForm = page.locator('form[action="/install"]');
    if (await installForm.count()) {
        await installForm.locator('#username').fill(ADMIN.username);
        await installForm.locator('#password').fill(ADMIN.password);
        await installForm.locator('button[type="submit"]').click();
    } else if (new URL(page.url()).pathname.startsWith('/login')) {
        const loginForm = page.locator('form:has(input[name="login_type"][value="user"])');
        await loginForm.locator('#username').fill(ADMIN.username);
        await loginForm.locator('#password').fill(ADMIN.password);
        await loginForm.locator('button[type="submit"]').click();
    }

    await page.waitForURL((url) => !url.pathname.startsWith('/login') && !url.pathname.startsWith('/install'));

    await page.context().storageState({ path: AUTH_FILE });
    await browser.close();
}
