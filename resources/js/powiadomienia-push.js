/*
 * Włączanie i wyłączanie Web Push na `/ustawienia/powiadomienia` (issue #35, D-303).
 *
 * ZGODA TYLKO PO KLIKNIĘCIU. `Notification.requestPermission()` pada wyłącznie
 * w obsłudze kliknięcia „Włącz powiadomienia na tym urządzeniu". Ten moduł
 * niczego nie pyta przy wczytaniu strony — ani tego ekranu, ani żadnego
 * innego (na innych ekranach w ogóle nie ma sekcji `[data-push]`).
 *
 * BEZ TEGO SKRYPTU oba przyciski zostają `hidden` (D-053), a widać zdanie,
 * że potrzebna jest przeglądarka z JavaScriptem. Wyłączenie na wszystkich
 * urządzeniach działa zwykłym formularzem.
 *
 * PO ODMOWIE NIE NALEGAMY. Przy `denied` przycisku nie ma — jest jedno zdanie,
 * gdzie to zmienić, jeśli ktoś sam zechce. Żadnych własnych okienek.
 *
 * WYLOGOWANIE (#1979): formularz `[data-wyloguj]` przed wysłaniem wypisuje
 * tę przeglądarkę z Web Push i podaje serwerowi jej adres — patrz
 * `przygotujWylogowanie()`.
 *
 * Czyste funkcje są eksportowane dla `powiadomienia-push.test.mjs` (Node).
 */

/** Klucz publiczny VAPID (base64url) na bajty dla `applicationServerKey`. */
export function kluczNaBajty(base64url) {
    const uzupelnienie = '='.repeat((4 - (base64url.length % 4)) % 4);
    const base64 = (base64url + uzupelnienie).replace(/-/g, '+').replace(/_/g, '/');
    const surowe = atob(base64);
    const bajty = new Uint8Array(surowe.length);
    for (let i = 0; i < surowe.length; i += 1) bajty[i] = surowe.charCodeAt(i);
    return bajty;
}

/** Nowsze przeglądarki umieją `aes128gcm` (RFC 8291); starsze tylko `aesgcm`. */
export function wybierzKodowanie(obslugiwane) {
    const lista = Array.isArray(obslugiwane) ? obslugiwane : [];
    return lista.includes('aes128gcm') || lista.length === 0 ? 'aes128gcm' : 'aesgcm';
}

/**
 * Co pokazać: zdanie i które przyciski.
 *
 * @param {{wspierane: boolean, zgoda: string, wlaczoneTutaj: boolean}} stan
 */
export function stanEkranu({ wspierane, zgoda, wlaczoneTutaj }) {
    if (!wspierane) {
        return {
            tekst: 'Ta przeglądarka nie obsługuje powiadomień. Na iPhonie i iPadzie działają one dopiero po dodaniu Kuking do ekranu początkowego.',
            wlacz: false,
            wylacz: false,
        };
    }

    if (zgoda === 'denied') {
        return {
            tekst: 'Powiadomienia z Kuking są zablokowane w ustawieniach tej przeglądarki. Jeśli chcesz je dostawać, zezwól na nie w ustawieniach strony — zwykle pod ikoną kłódki obok adresu.',
            wlacz: false,
            wylacz: false,
        };
    }

    if (wlaczoneTutaj && zgoda === 'granted') {
        return { tekst: 'Powiadomienia na tym urządzeniu są włączone.', wlacz: false, wylacz: true };
    }

    return { tekst: 'Powiadomienia na tym urządzeniu są wyłączone.', wlacz: true, wylacz: false };
}

async function skrot(tekst) {
    const bajty = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(tekst));
    return Array.from(new Uint8Array(bajty), (b) => b.toString(16).padStart(2, '0')).join('');
}

function gotowyWorker() {
    // `ready` czeka w nieskończoność, gdy worker się nie zarejestrował —
    // człowiek nie może zostać z przyciskiem, który „myśli" bez końca.
    return Promise.race([
        navigator.serviceWorker.ready,
        new Promise((_, odrzuc) => setTimeout(() => odrzuc(new Error('worker')), 10000)),
    ]);
}

function setup(sekcja) {
    if (sekcja.dataset.pushReady) return;
    sekcja.dataset.pushReady = '1';

    const stanEl = sekcja.querySelector('[data-push-stan]');
    const komunikat = sekcja.querySelector('[data-push-komunikat]');
    const wlacz = sekcja.querySelector('[data-push-przycisk-wlacz]');
    const wylacz = sekcja.querySelector('[data-push-przycisk-wylacz]');
    const klucz = sekcja.dataset.pushKlucz || '';
    const csrf = sekcja.dataset.pushCsrf || '';
    let znane = [];
    try { znane = JSON.parse(sekcja.dataset.pushZnane || '[]'); } catch { znane = []; }

    const wspierane = 'serviceWorker' in navigator && 'PushManager' in window
        && 'Notification' in window && window.isSecureContext && Boolean(klucz);

    const pokaz = (stan) => {
        if (stanEl) stanEl.textContent = stan.tekst;
        if (wlacz) wlacz.hidden = !stan.wlacz;
        if (wylacz) wylacz.hidden = !stan.wylacz;
    };

    const powiedz = (tekst) => { if (komunikat) komunikat.textContent = tekst; };

    const wyslij = (adres, metoda, dane) => fetch(adres, {
        method: metoda,
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(dane),
    });

    async function odswiez() {
        if (!wspierane) { pokaz(stanEkranu({ wspierane: false, zgoda: 'default', wlaczoneTutaj: false })); return; }
        let wlaczoneTutaj = false;
        try {
            const rejestracja = await navigator.serviceWorker.getRegistration('/');
            const subskrypcja = rejestracja ? await rejestracja.pushManager.getSubscription() : null;
            // Włączone TUTAJ = przeglądarka ma subskrypcję I serwer ją zna.
            // Po „Wyłącz na wszystkich urządzeniach" przeglądarka ją jeszcze
            // trzyma, ale nic na nią nie wyślemy — więc to jest „wyłączone".
            wlaczoneTutaj = subskrypcja !== null && znane.includes(await skrot(subskrypcja.endpoint));
        } catch { wlaczoneTutaj = false; }
        pokaz(stanEkranu({ wspierane: true, zgoda: Notification.permission, wlaczoneTutaj }));
    }

    wlacz?.addEventListener('click', async () => {
        wlacz.disabled = true;
        powiedz('Przeglądarka zapyta teraz o zgodę na powiadomienia.');
        try {
            const zgoda = await Notification.requestPermission();
            if (zgoda !== 'granted') {
                powiedz(zgoda === 'denied'
                    ? 'Przeglądarka nie pozwoliła na powiadomienia. Nic się nie zmieniło.'
                    : 'Nie włączyliśmy powiadomień, bo przeglądarka nie dostała zgody. Możesz spróbować jeszcze raz.');
                await odswiez();
                return;
            }
            const rejestracja = await gotowyWorker();
            const subskrypcja = await rejestracja.pushManager.getSubscription()
                ?? await rejestracja.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: kluczNaBajty(klucz) });
            const json = subskrypcja.toJSON();
            const odpowiedz = await wyslij(sekcja.dataset.pushZapisz, 'POST', {
                endpoint: json.endpoint,
                keys: json.keys,
                contentEncoding: wybierzKodowanie(window.PushManager?.supportedContentEncodings),
            });
            if (!odpowiedz.ok) {
                const blad = await odpowiedz.json().catch(() => ({}));
                const pierwszy = blad?.errors ? Object.values(blad.errors)[0]?.[0] : null;
                powiedz(pierwszy || 'Nie udało się włączyć powiadomień. Odśwież stronę i spróbuj jeszcze raz.');
                return;
            }
            window.location.reload();
        } catch {
            powiedz('Nie udało się włączyć powiadomień na tym urządzeniu. Odśwież stronę i spróbuj jeszcze raz.');
        } finally {
            wlacz.disabled = false;
        }
    });

    wylacz?.addEventListener('click', async () => {
        wylacz.disabled = true;
        try {
            const rejestracja = await navigator.serviceWorker.getRegistration('/');
            const subskrypcja = rejestracja ? await rejestracja.pushManager.getSubscription() : null;
            if (subskrypcja) {
                const odpowiedz = await wyslij(sekcja.dataset.pushWylacz, 'DELETE', { endpoint: subskrypcja.endpoint });
                if (!odpowiedz.ok) throw new Error('serwer');
                await subskrypcja.unsubscribe();
            }
            window.location.reload();
        } catch {
            powiedz('Nie udało się wyłączyć powiadomień. Spróbuj jeszcze raz albo użyj przycisku „Wyłącz na wszystkich urządzeniach”.');
        } finally {
            wylacz.disabled = false;
        }
    });

    odswiez();
}

/**
 * Przed wylogowaniem (#1979): adres subskrypcji tej przeglądarki dla serwera
 * i `unsubscribe()` w przeglądarce. Po „Wyloguj się" nikt na tym urządzeniu
 * nie jest zalogowany, więc żadna subskrypcja, która tu została, nie ma już
 * dla kogo pokazywać powiadomień — także taka po kimś, kto wcześniej nie
 * wylogował się sam.
 *
 * NIGDY NIE ZATRZYMUJE WYLOGOWANIA. Każdy błąd i każde zawieszenie kończy
 * się pustym adresem po `limitMs`; wtedy serwer i tak gasi urządzenie
 * rozpoznane po sesji (`OdlaczUrzadzeniePush`).
 *
 * @param {object} nav  `navigator` (w testach atrapa)
 * @param {number} limitMs
 * @returns {Promise<string>} adres subskrypcji albo ''
 */
export function przygotujWylogowanie(nav, limitMs = 3000) {
    const praca = (async () => {
        if (!nav || !nav.serviceWorker || typeof nav.serviceWorker.getRegistration !== 'function') return '';
        const rejestracja = await nav.serviceWorker.getRegistration('/');
        const subskrypcja = rejestracja && rejestracja.pushManager
            ? await rejestracja.pushManager.getSubscription()
            : null;
        if (!subskrypcja) return '';
        const adres = typeof subskrypcja.endpoint === 'string' ? subskrypcja.endpoint : '';
        try { await subskrypcja.unsubscribe(); } catch { /* serwer i tak skasuje wiersz */ }
        return adres;
    })().catch(() => '');

    return Promise.race([praca, new Promise((gotowe) => setTimeout(() => gotowe(''), limitMs))]);
}

function setupWylogowanie(formularz) {
    if (formularz.dataset.wylogujReady) return;
    formularz.dataset.wylogujReady = '1';

    formularz.addEventListener('submit', async (zdarzenie) => {
        if (formularz.dataset.wylogujWysylane) return;
        zdarzenie.preventDefault();
        formularz.dataset.wylogujWysylane = '1';
        const pole = formularz.querySelector('[data-wyloguj-push]');
        const adres = await przygotujWylogowanie(typeof navigator !== 'undefined' ? navigator : null);
        if (pole) pole.value = adres;
        // `submit()` nie wywołuje ponownie zdarzenia `submit`.
        formularz.submit();
    });
}

if (typeof document !== 'undefined') {
    const start = () => {
        document.querySelectorAll('[data-push]').forEach(setup);
        document.querySelectorAll('form[data-wyloguj]').forEach(setupWylogowanie);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
}
