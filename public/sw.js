/*
 * Kuking — service worker.
 *
 * ZASADA NADRZĘDNA: nie cache'ujemy niczego, co jest prywatne.
 *
 * W cache trafiają wyłącznie statyczne zasoby (CSS, JS, ikony) oraz strona
 * „jesteś offline”. Odpowiedzi HTML zalogowanego użytkownika NIGDY nie idą
 * do cache — na współdzielonym komputerze (a taki bywa w domu) oznaczałoby
 * to pokazanie cudzego feedu następnej osobie.
 *
 * Strategia:
 *  - nawigacja (HTML): sieć, a przy jej braku strona offline;
 *  - zasoby z /build/: cache-first, bo są wersjonowane przez Vite;
 *  - ikony i manifest: sieć, a przy jej braku ostatnia zapisana odpowiedź;
 *  - wszystko inne: przepuszczamy do sieci bez dotykania.
 */

// Zmieniaj przy zmianie zasobów startowych, w tym strony offline.
const WERSJA = 'kuking-alfa-013';
const OFFLINE_URL = '/offline.html';

const ZASOBY_STARTOWE = [
    OFFLINE_URL,
    '/icons/kuking-mark.svg',
    '/icons/kuking-icon-192.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(WERSJA).then((cache) => cache.addAll(ZASOBY_STARTOWE))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((klucze) => Promise.all(
                klucze.filter((k) => k !== WERSJA).map((k) => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Tylko GET. POST-y (publikacja wpisu, komentarz) muszą iść do sieci.
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }

    const cacheowalne = url.pathname.startsWith('/build/')
        || url.pathname.startsWith('/icons/')
        || url.pathname === '/manifest.webmanifest';

    if (! cacheowalne) {
        return;
    }

    // Ikony i manifest mają stałe adresy, bez skrótu Vite. Cache-first
    // utrzymywał stary znak nawet po wdrożeniu nowej identyfikacji.
    if (! url.pathname.startsWith('/build/')) {
        event.respondWith(
            fetch(request).then(async (odpowiedz) => {
                if (odpowiedz.ok && odpowiedz.type === 'basic') {
                    try {
                        const cache = await caches.open(WERSJA);
                        await cache.put(request, odpowiedz.clone());
                    } catch {
                        // Pełny magazyn nie unieważnia odpowiedzi z sieci.
                    }
                }

                return odpowiedz;
            }).catch(() => caches.match(request))
        );
        return;
    }

    event.respondWith(
        caches.match(request).then((trafienie) => trafienie || fetch(request).then((odpowiedz) => {
            if (odpowiedz.ok && odpowiedz.type === 'basic') {
                const kopia = odpowiedz.clone();
                // Zapis wiążemy ze zdarzeniem: bez waitUntil przeglądarka może
                // zakończyć workera przed końcem put(). Odmowa magazynu nie
                // psuje odpowiedzi z sieci.
                event.waitUntil(
                    caches.open(WERSJA)
                        .then((cache) => cache.put(request, kopia))
                        .catch(() => {})
                );
            }

            return odpowiedz;
        }))
    );
});
