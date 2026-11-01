import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { ADMIN, AUTH_FILE } from '../support/env.js';

test.use({ storageState: AUTH_FILE });

const APP_CONTAINER = process.env.E2E_APP_CONTAINER ?? 'phuppi-e2e-app-1';

test.describe('Data repair: orphaned files', () => {
    test('ignores generated preview folders', async ({ request }) => {
        const stamp = Date.now();
        const orphan = `orphan-${stamp}.txt`;
        const dir = `/var/www/data/uploads/${ADMIN.username}`;

        // Stored files with no database record: one real orphan, plus preview files
        execFileSync('docker', ['exec', APP_CONTAINER, 'sh', '-c', [
            `mkdir -p ${dir}/previews ${dir}/video-previews`,
            `echo orphan > ${dir}/${orphan}`,
            `echo x > ${dir}/previews/preview-${stamp}.webp`,
            `echo x > ${dir}/video-previews/preview-${stamp}.mp4`,
            `echo x > ${dir}/video-previews/poster-${stamp}.jpg`,
        ].join(' && ')]);

        try {
            const response = await request.post('/admin/settings/storage', {
                form: { action: 'scan_orphaned_files' },
            });
            expect(response.ok()).toBeTruthy();
            const paths = (await response.json()).orphaned_files.map((f) => f.path);

            expect(paths).toContain(`${ADMIN.username}/${orphan}`);
            expect(paths.filter((p) => p.includes('/previews/') || p.includes('/video-previews/'))).toEqual([]);
        } finally {
            execFileSync('docker', ['exec', APP_CONTAINER, 'sh', '-c',
                `rm -f ${dir}/${orphan} ${dir}/previews/preview-${stamp}.webp ${dir}/video-previews/*-${stamp}.*`]);
        }
    });
});
