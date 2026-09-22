/* Adres wydania pochodzi z tego samego HTML-u co bieżąca aplikacja.
   Nowy URL omija starą odpowiedź CDN, a scope pozostaje wspólny: aktualizujemy
   istniejącego workera, zamiast zakładać obok niego kolejną rejestrację. */
function zarejestrujWorker() {
    const adres = document.querySelector('meta[name="kuking-service-worker"]')?.content;
    if (!adres) return;

    navigator.serviceWorker.register(adres, { scope: '/', updateViaCache: 'none' }).catch(() => {
        // Offline lub brak miejsca nie może zablokować korzystania z aplikacji.
    });
}

if ('serviceWorker' in navigator) {
    // Moduł może dotrzeć także po load, np. po późnym imporcie.
    if (document.readyState === 'complete') zarejestrujWorker();
    else window.addEventListener('load', zarejestrujWorker, { once: true });
}
