/*
 * Okresowe sprawdzanie, czy postęp gotowania zmienił się na innym urządzeniu
 * (issue #2016).
 *
 * CO TO ROBI. Gdy osoba włączyła zapamiętywanie postępu na koncie, strona
 * niesie numer rewizji, którą widziała. Ten moduł co jakiś czas pyta serwer
 * o aktualną rewizję (`GET .../gotuj/postep`, sam numer, bez listy kroków)
 * i — jeśli jest inna albo zapamiętywanie zniknęło — odkrywa pas z odnośnikiem
 * „Pokaż aktualny postęp”. Niczego nie zapisuje: odhaczenia idą zwykłym
 * formularzem POST, więc działają bez skryptu i od razu trafiają na konto.
 *
 * BEZ SKRYPTU pas ma `hidden` i zostaje ukryty (D-053: żadnego martwego
 * przycisku); nawet wtedy każde przeładowanie strony pokazuje stan z konta.
 *
 * SZCZEGÓŁY: pytamy tylko, gdy karta jest widoczna (ukryta karta nie ma po co
 * budzić serwera), od razu po powrocie do karty, a co `INTERWAL_MS`
 * w trakcie patrzenia. Błąd sieci jest po cichu pomijany — brak łączności
 * w kuchni nie ma prawa niczego psuć ani niczym straszyć.
 */

/** Jak często pytać, gdy karta jest widoczna. */
export const INTERWAL_MS = 30_000;

/**
 * Co powiedzieć osobie po odpowiedzi serwera; null = nic się nie zmieniło
 * (albo odpowiedź jest nieczytelna).
 *
 * @param {unknown} odpowiedz surowy JSON z serwera
 * @param {number} widzianaRewizja rewizja zapisana w stronie
 * @returns {string|null}
 */
export function komunikatOZmianie(odpowiedz, widzianaRewizja) {
    if (odpowiedz === null || typeof odpowiedz !== 'object') return null;

    const {aktywna, rewizja} = /** @type {{aktywna?: unknown, rewizja?: unknown}} */ (odpowiedz);

    if (aktywna === false) {
        return 'Zapamiętywanie postępu na koncie wygasło albo zostało wyłączone na innym urządzeniu.';
    }

    if (aktywna === true && Number.isInteger(rewizja) && rewizja !== widzianaRewizja) {
        return 'Postęp tego przepisu zmienił się na innym urządzeniu.';
    }

    return null;
}

/**
 * @param {HTMLElement} pas element z `data-postep-rewizja` i `data-postep-adres`
 * @param {{fetch: typeof fetch, document: Document, ustawCzas: typeof setInterval}} srodowisko
 */
export function podlaczSprawdzanie(pas, srodowisko) {
    if (pas.dataset.postepGotowe) return;

    const rewizja = Number.parseInt(pas.dataset.postepRewizja ?? '', 10);
    const adres = pas.dataset.postepAdres ?? '';

    if (!Number.isInteger(rewizja) || adres === '') return;

    const tekst = pas.querySelector('[data-postep-tekst]');
    let trwaZapytanie = false;
    let pokazano = false;

    const zapytaj = async () => {
        if (pokazano || trwaZapytanie || srodowisko.document.visibilityState === 'hidden') return;

        trwaZapytanie = true;
        try {
            const odp = await srodowisko.fetch(adres, {
                credentials: 'same-origin',
                headers: {Accept: 'application/json'},
            });

            if (!odp.ok) return;

            const komunikat = komunikatOZmianie(await odp.json(), rewizja);

            if (komunikat !== null) {
                if (tekst) tekst.textContent = komunikat;
                pas.hidden = false;
                pokazano = true;
            }
        } catch {
            // Brak łączności albo nieczytelna odpowiedź: spróbujemy przy następnym razie.
        } finally {
            trwaZapytanie = false;
        }
    };

    pas.dataset.postepGotowe = '1';
    srodowisko.ustawCzas(zapytaj, INTERWAL_MS);
    srodowisko.document.addEventListener('visibilitychange', zapytaj);
}

function init() {
    document.querySelectorAll('[data-postep-synchronizacja]').forEach((pas) => {
        if (pas instanceof HTMLElement) {
            podlaczSprawdzanie(pas, {
                fetch: window.fetch.bind(window),
                document,
                ustawCzas: window.setInterval.bind(window),
            });
        }
    });
}

if (typeof document !== 'undefined' && typeof window !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, {once: true});
    } else {
        init();
    }
}
