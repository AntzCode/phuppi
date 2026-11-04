/**
 * Phuppi service worker.
 *
 * Receives files shared into the installed web app (Web Share Target, POST /share-target),
 * keeps them in IndexedDB so they survive a login redirect or a dropped connection, and
 * sends the browser on to the receiving page that uploads them.
 */

const DB_NAME = 'phuppi-share';
const STORE = 'pending';

const openDb = () => new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => request.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
});

const storeFiles = async (files) => {
    const db = await openDb();
    await new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readwrite');
        for (const file of files) {
            tx.objectStore(STORE).add({ name: file.name, type: file.type, size: file.size, added: Date.now(), blob: file });
        }
        tx.oncomplete = resolve;
        tx.onerror = () => reject(tx.error);
    });
    db.close();
};

const handleShare = async (request) => {
    try {
        const formData = await request.formData();
        const files = formData.getAll('files').filter((entry) => typeof entry !== 'string');
        if (files.length) {
            await storeFiles(files);
        }
    } catch (error) {
        console.error('Phuppi share target failed to store files', error);
    }
    return Response.redirect('/share-target/receive', 303);
};

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (event.request.method === 'POST' && url.pathname === '/share-target') {
        event.respondWith(handleShare(event.request));
    }
});
