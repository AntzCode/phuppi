import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';
import { AUTH_FILE } from '../support/env.js';

test.use({ storageState: AUTH_FILE });

const fixture = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'fixtures', 'exif-rotated.jpg');

test.describe('Image previews', () => {
    test('thumbnails honor the EXIF orientation of the photo', async ({ page, request }) => {
        // The fixture is stored landscape (left half red, right half blue) with EXIF orientation 6,
        // so shown upright it is portrait with the red half on top
        const name = `exif-${Date.now()}.jpg`;
        const upload = await request.post('/files', {
            multipart: { file: { name, mimeType: 'image/jpeg', buffer: fs.readFileSync(fixture) } },
        });
        expect(upload.ok()).toBeTruthy();

        const list = await (await request.get('/files?limit=50&sort=date_newest')).json();
        const file = list.files.find((f) => f.display_filename === name);
        expect(file).toBeTruthy();

        const generated = await request.post(`/api/preview/generate/${file.id}`);
        expect(generated.ok()).toBeTruthy();

        const preview = await request.get(`/files/preview/${file.id}`);
        expect(preview.headers()['content-type']).toContain('image/jpeg');

        await page.goto('/');
        const [top, bottom, width, height] = await page.evaluate(async (base64) => {
            const image = new Image();
            image.src = `data:image/jpeg;base64,${base64}`;
            await image.decode();
            const canvas = document.createElement('canvas');
            canvas.width = image.width;
            canvas.height = image.height;
            const context = canvas.getContext('2d');
            context.drawImage(image, 0, 0);
            const sample = (y) => [...context.getImageData(Math.floor(image.width / 2), y, 1, 1).data.slice(0, 3)];
            return [sample(Math.floor(image.height * 0.2)), sample(Math.floor(image.height * 0.8)), image.width, image.height];
        }, (await preview.body()).toString('base64'));

        // The fixture is 20x40 once upright: the thumbnail keeps that 1:2 shape instead of stretching to a square
        expect(height).toBe(width * 2);

        // Red on top, blue underneath (JPEG compression allows some drift)
        expect(top[0]).toBeGreaterThan(180);
        expect(top[2]).toBeLessThan(80);
        expect(bottom[2]).toBeGreaterThan(180);
        expect(bottom[0]).toBeLessThan(80);
    });
});
