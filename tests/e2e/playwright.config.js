import { defineConfig, devices } from '@playwright/test';
import { APP_URL } from './support/env.js';

export default defineConfig({
    testDir: './specs',
    timeout: 60_000,
    expect: { timeout: 15_000 },
    // One worker: every test shares the single admin account on the throwaway stack
    workers: 1,
    globalSetup: './support/global-setup.js',
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL: APP_URL,
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                launchOptions: {
                    // Expose real host ICE candidates so two local browsers connect without mDNS
                    args: ['--disable-features=WebRtcHideLocalIpsWithMdns'],
                },
            },
        },
    ],
});
