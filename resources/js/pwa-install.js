// Jedna propozycja na konto. O jej rezerwacji i decyzjach rozstrzyga serwer.
let cleanup = () => {};

function initialize() {
    cleanup();
    cleanup = () => {};
    const panel = document.querySelector('[data-pwa-install]');
    if (!panel) return;

    const standalone = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    if (standalone()) return;
    const accept = panel.querySelector('[data-pwa-accept]');
    const dismiss = panel.querySelector('[data-pwa-dismiss]');
    const status = panel.querySelector('[data-pwa-status]');
    const csrf = panel.querySelector('input[name="_token"]')?.value;
    const context = panel.dataset.pwaContext;
    const endpoint = new URL(panel.dataset.pwaUrl, location.href);
    if (!csrf || !context || endpoint.origin !== location.origin) return;

    const events = new AbortController();
    const listen = (target, name, handler) => target.addEventListener(name, handler, {signal: events.signal});
    let active = true;
    let deferredPrompt = null;
    let offering = false;
    let reserved = false;
    let consumed = false;
    let shown = false;
    let installationObserved = false;
    let focusAfterPrompt = false;
    let completion = Promise.resolve();
    let observer = null;

    const send = async (action) => {
        const response = await fetch(endpoint.href, {
            method: 'POST', credentials: 'same-origin', keepalive: true,
            headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf},
            body: JSON.stringify({context, action}),
            signal: AbortSignal.timeout(10000),
        });
        if (!response.ok) throw new Error('Nie zapisano decyzji PWA.');
        return (await response.json()).changed === true;
    };

    // appinstalled może nadejść przed rozwiązaniem userChoice. Kolejka
    // zachowuje kolejność request -> wynik, bez wnioskowania o instalacji.
    const enqueue = (action) => {
        const next = completion.then(() => send(action));
        completion = next.catch(() => false);
        return completion;
    };

    const hide = () => {
        const moveFocus = panel.contains(document.activeElement)
            || (focusAfterPrompt && document.activeElement === document.body);
        focusAfterPrompt = false;
        panel.hidden = true;
        observer?.disconnect();
        if (moveFocus && active) {
            const target = document.querySelector('.start-feed-wybor a')
                || document.querySelector('.marka-publikacja a');
            target?.focus();
        }
    };

    const recordShown = () => {
        if (!active || !panel.isConnected || panel.hidden || shown || document.visibilityState !== 'visible') return;
        const r = panel.getBoundingClientRect();
        if (r.bottom <= 0 || r.top >= innerHeight || r.right <= 0 || r.left >= innerWidth) return;
        shown = true;
        observer?.disconnect();
        void enqueue('shown');
    };

    const reveal = () => {
        if (!active || !panel.isConnected || consumed || standalone() || !panel.hidden
            || document.visibilityState !== 'visible') return;
        panel.hidden = false;
        // Samo zdjęcie hidden poza viewportem nie jest jeszcze ekspozycją.
        if ('IntersectionObserver' in window) {
            observer = new IntersectionObserver(recordShown);
            observer.observe(panel);
        }
        recordShown();
    };

    const offer = async () => {
        if (reserved) { reveal(); return; }
        if (!active || !deferredPrompt || offering || consumed || standalone()
            || document.visibilityState !== 'visible') return;
        offering = true;
        const changed = await enqueue('offer');
        if (!changed) {
            consumed = true;
            deferredPrompt = null;
            return;
        }
        reserved = true;
        if (installationObserved) void enqueue('installed');
        reveal();
    };

    listen(window, 'beforeinstallprompt', (event) => {
        if (typeof event.prompt !== 'function' || consumed || standalone()) return;
        event.preventDefault();
        deferredPrompt = event;
        void offer();
    });
    listen(document, 'visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            void offer();
            recordShown();
        }
    });
    listen(window, 'scroll', recordShown);
    listen(window, 'resize', recordShown);

    listen(accept, 'click', () => {
        if (!active || consumed || !reserved || !deferredPrompt) return;
        const prompt = deferredPrompt;
        deferredPrompt = null;
        consumed = true;
        focusAfterPrompt = panel.contains(document.activeElement);
        accept.disabled = true;
        recordShown();
        void enqueue('request');

        let result;
        try {
            // Musi być w tym samym zdarzeniu kliknięcia, PRZED pierwszym await.
            result = prompt.prompt();
        } catch {
            status.textContent = 'Nie udało się otworzyć instalacji. Możesz dalej korzystać z serwisu w przeglądarce.';
            return;
        }
        Promise.resolve(result).then(() => prompt.userChoice).then((choice) => {
            if (choice?.outcome === 'dismissed') void enqueue('dismiss');
            // accepted nie jest sygnałem appinstalled.
            if (active) hide();
        }).catch(() => {
            // Awaria API nie jest odmową użytkownika ani instalacją.
            if (active) status.textContent = 'Nie udało się otworzyć instalacji. Możesz dalej korzystać z serwisu w przeglądarce.';
        });
    });

    listen(dismiss, 'click', () => {
        consumed = true;
        deferredPrompt = null;
        recordShown();
        void enqueue('dismiss');
        hide();
    });
    listen(window, 'appinstalled', () => {
        installationObserved = true;
        consumed = true;
        deferredPrompt = null;
        if (reserved) void enqueue('installed');
        hide();
    });

    cleanup = () => {
        active = false;
        events.abort();
        observer?.disconnect();
        panel.hidden = true;
        deferredPrompt = null;
        // Rozpoczęte zapisy mogą dokończyć się po nawigacji. Ich kontekst
        // nadal wiąże je z dawnym kontem i sesją, bez dotykania nowego DOM.
    };
}

initialize();
document.addEventListener('livewire:navigating', () => cleanup());
document.addEventListener('livewire:navigated', initialize);
