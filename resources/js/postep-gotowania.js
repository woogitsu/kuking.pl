/*
 * Okresowe sprawdzanie, czy postęp gotowania zmienił się na innym urządzeniu
 * (issue #2016).
 *
 * CO TO ROBI. Gdy osoba włączyła zapamiętywanie postępu na koncie, strona
 * niesie numer rewizji i UUID wiersza, które widziała. Ten moduł co jakiś czas
 * pyta serwer o oba znaczniki (`GET .../gotuj/postep`, bez listy kroków)
 * i — jeśli któryś jest inny albo zapamiętywanie zniknęło — odkrywa pas z odnośnikiem
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
 * @param {{zmiana?: string, koniec?: string}} [teksty] własne zdania (wspólne gotowanie, #2385)
 * @param {string|null} [widzianyPostepId] UUID zapamiętywania z tej karty
 * @returns {string|null}
 */
export function komunikatOZmianie(odpowiedz, widzianaRewizja, teksty = {}, widzianyPostepId = null) {
    if (odpowiedz === null || typeof odpowiedz !== 'object') return null;

    const {aktywna, rewizja, id_postepu: idPostepu} = /** @type {{aktywna?: unknown, rewizja?: unknown, id_postepu?: unknown}} */ (odpowiedz);

    if (aktywna === false) {
        return teksty.koniec ?? 'Zapamiętywanie postępu na koncie wygasło albo zostało wyłączone na innym urządzeniu.';
    }

    if (aktywna === true && Number.isInteger(rewizja) && (
        rewizja !== widzianaRewizja || (widzianyPostepId !== null && typeof idPostepu === 'string' && idPostepu !== widzianyPostepId)
    )) {
        return teksty.zmiana ?? 'Postęp tego przepisu zmienił się na innym urządzeniu.';
    }

    return null;
}

/**
 * Odpowiedź serwera inna niż 2xx. 403 i 404 znaczą dla wspólnego gotowania
 * (#2385), że sesja się skończyła albo osoba straciła do niej dostęp — wtedy
 * (i tylko gdy strona dała własne zdanie `koniec`) mówimy to wprost, zamiast
 * milczeć. Inne kody (500, 429, brak sieci) są po cichu pomijane.
 *
 * @param {number} status kod HTTP
 * @param {{koniec?: string}} [teksty]
 * @returns {string|null}
 */
export function komunikatOStatusie(status, teksty = {}) {
    if ((status === 403 || status === 404) && typeof teksty.koniec === 'string' && teksty.koniec !== '') {
        return teksty.koniec;
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
    const idPostepu = pas.dataset.postepId ?? null;
    const adres = pas.dataset.postepAdres ?? '';

    if (!Number.isInteger(rewizja) || adres === '') return;

    // Region live może stać POZA pasem (stały, pusty element w DOM — czytniki
    // ekranu ogłaszają tekst wstawiony do istniejącego regionu); bez
    // `data-postep-region` tekst jest w środku pasa jak dawniej.
    const idRegionu = pas.dataset.postepRegion;
    const tekst = (idRegionu ? srodowisko.document.getElementById?.(idRegionu) : null)
        ?? pas.querySelector('[data-postep-tekst]');
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

            const teksty = {
                zmiana: pas.dataset.postepKomunikatZmiana,
                koniec: pas.dataset.postepKomunikatKoniec,
            };

            const komunikat = odp.ok
                ? komunikatOZmianie(await odp.json(), rewizja, teksty, idPostepu)
                : komunikatOStatusie(odp.status, teksty);

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
