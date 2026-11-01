import http from 'node:http';
import { test, expect } from '@playwright/test';
import { APP_URL } from '../support/env.js';

/** Sends the path exactly as given; fetch/Playwright would normalize "../" away. */
function rawGet(path) {
    const { hostname, port } = new URL(APP_URL);
    return new Promise((resolve, reject) => {
        http.get({ hostname, port, path }, (res) => {
            let body = '';
            res.on('data', (chunk) => (body += chunk));
            res.on('end', () => resolve({ status: res.statusCode, body }));
        }).on('error', reject);
    });
}

test.describe('Docs route', () => {
    test('serves markdown from public/docs', async () => {
        const response = await rawGet('/docs/cli-configuration');
        expect(response.status).toBe(200);
    });

    for (const path of ['/docs/../../src/routes.php', '/docs/../../src/routes', '/docs/../index.php']) {
        test(`refuses to escape public/docs: ${path}`, async () => {
            const response = await rawGet(path);
            expect(response.status).not.toBe(200);
            expect(response.body).not.toContain('Flight::');
        });
    }
});
