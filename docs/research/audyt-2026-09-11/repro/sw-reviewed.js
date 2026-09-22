// Executable statements from public/sw.js; comments removed.
// Repository commit: 8fa3cc9475c8deda02da819e7b8bb5ad1a8f5648
// Upstream blob: 40233698efa183047fc928e2021abc53942bb784
const WERSJA = 'kuking-v1';
const OFFLINE_URL = '/offline.html';
const ZASOBY_STARTOWE = [OFFLINE_URL, '/icons/kuking-mark.svg', '/icons/kuking-icon-192.png'];
self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(WERSJA).then((cache) => cache.addAll(ZASOBY_STARTOWE)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys().then((klucze) => Promise.all(klucze.filter((k) => k !== WERSJA).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
        return;
    }
    const cacheowalne = url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/') || url.pathname === '/manifest.webmanifest';
    if (!cacheowalne) return;
    event.respondWith(caches.match(request).then((trafienie) => trafienie || fetch(request).then((odpowiedz) => {
        if (odpowiedz.ok && odpowiedz.type === 'basic') {
            const kopia = odpowiedz.clone();
            caches.open(WERSJA).then((cache) => cache.put(request, kopia));
        }
        return odpowiedz;
    })));
});
