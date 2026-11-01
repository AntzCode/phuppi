import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));

export const APP_URL = process.env.E2E_APP_URL ?? `http://localhost:${process.env.E2E_APP_PORT ?? 8089}`;

export const PEERJS = {
    host: process.env.E2E_PEERJS_HOST ?? 'localhost',
    port: Number(process.env.E2E_PEERJS_PORT ?? 9089),
};

export const ADMIN = {
    username: process.env.E2E_ADMIN_USERNAME ?? 'e2e-admin',
    password: process.env.E2E_ADMIN_PASSWORD ?? 'e2e-password',
};

export const AUTH_FILE = path.join(here, '..', '.auth', 'admin.json');
