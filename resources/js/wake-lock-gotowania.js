/*
 * Wake Lock trybu gotowania (issue #24) — logika stanu wydzielona z DOM-u
 * i z prawdziwego `navigator.wakeLock`, żeby dało się ją przetestować bez
 * przeglądarki (issue #739: automatyczne zwolnienie blokady nie wracało
 * po powrocie do karty).
 *
 * CO SIĘ PSUŁO
 *
 * Zmienna `blokada` trzymała referencję do obiektu blokady, a warunek
 * odzyskania po powrocie na kartę pytał `blokada === null`. Nasłuch
 * zdarzenia `release` (przeglądarka sama zwalnia blokadę np. przy zmianie
 * karty) aktualizował TYLKO tekst komunikatu — nigdy nie zerował samej
 * zmiennej. Efekt: po jednym automatycznym zwolnieniu `blokada` na zawsze
 * wskazywała na już-nieaktywny obiekt, `blokada === null` nigdy nie było
 * prawdą, a powrót na kartę nigdy nie odzyskiwał blokady, mimo że
 * przełącznik nadal był zaznaczony. Człowiek wracał do kuchni ufając
 * napisowi „ekran nie zgaśnie”, sprzed jednego przełączenia karty.
 */

/**
 * @param {() => Promise<{addEventListener(event: 'release', cb: () => void): void, release(): Promise<void>}>} zazadajBlokady
 *        odpowiednik `navigator.wakeLock.request('screen')`
 * @param {(zaznaczony: boolean) => void} naZmianeStanu wywoływane z `true`,
 *        gdy blokada jest aktywna, i z `false`, gdy nie jest (bezpośrednio
 *        po wyłączeniu, po awarii `request()` albo po automatycznym
 *        zwolnieniu) — wywołujący aktualizuje nim tekst i checkbox.
 */
export function utworzKontrolerWakeLock(zazadajBlokady, naZmianeStanu) {
    let blokada = null;

    async function wlacz() {
        try {
            blokada = await zazadajBlokady();

            blokada.addEventListener('release', () => {
                // POPRAWKA #739: zerujemy stan PRZED powiadomieniem, żeby
                // kolejne sprawdzenie jestAktywna() poprawnie zwróciło
                // false i pozwoliło się odzyskać po powrocie na kartę.
                blokada = null;
                naZmianeStanu(false);
            });

            naZmianeStanu(true);

            return true;
        } catch {
            // Np. system oszczędza baterię i odmawia blokady.
            blokada = null;
            naZmianeStanu(false);

            return false;
        }
    }

    async function wylacz() {
        // Nie wywolujemy tu naZmianeStanu(false) osobno: prawdziwe
        // WakeLockSentinel#release() samo wyzwala zdarzenie 'release',
        // ktore juz to robi (patrz nasluch w wlacz()) -- podwojne
        // wywolanie zdublowaloby powiadomienie o tym samym przejsciu stanu.
        await blokada?.release();
    }

    function jestAktywna() {
        return blokada !== null;
    }

    return {wlacz, wylacz, jestAktywna};
}

/*
 * WYBÓR PRZEŻYWA ZMIANĘ KROKU (issue #1302).
 *
 * Każdy krok trybu gotowania to osobny dokument: „Następny krok” to GET
 * pod nowy `?krok=`, „Oznacz krok jako zrobiony” to POST z przekierowaniem.
 * Blokada ekranu żyje tylko w dokumencie, który o nią poprosił, a checkbox
 * nowego dokumentu przychodzi z serwera odznaczony — więc zaznaczenie
 * z kroku 1 przepadało na kroku 2 i telefon w kuchni gasł, choć człowiek
 * raz wyraźnie poprosił, żeby nie gasł.
 *
 * Zapamiętujemy więc INTENCJĘ (nie samą blokadę, której przenieść się nie
 * da) w `sessionStorage`: tylko ta karta, tylko ten przepis. Nowy dokument
 * odczytuje ją i prosi o blokadę od nowa; odmowę pokazuje wywołujący tym
 * samym `naZmianeStanu(false)` co zawsze, więc checkbox nie udaje stanu,
 * którego nie ma. Intencję zmienia WYŁĄCZNIE ręczna zmiana przełącznika
 * i wyjście przez „Zakończ gotowanie” — automatyczne zwolnienie blokady
 * albo odmowa przeglądarki jej nie kasują, bo człowiek niczego nie wyłączał.
 */

/** Klucz intencji w `sessionStorage` — osobny dla każdego przepisu. */
export function kluczWyboru(recipeSlug) {
    return `kuking.wakelock.${recipeSlug}`;
}

/**
 * @param {{getItem(k: string): string|null, setItem(k: string, v: string): void, removeItem(k: string): void}|null} pamiec
 *        zwykle `window.sessionStorage`; bywa niedostępna (tryb prywatny,
 *        zablokowane dane witryny) — wtedy wybór po prostu nie przeżywa
 *        zmiany kroku, a przełącznik dalej działa w bieżącym dokumencie.
 */
export function utworzPamiecWyboru(pamiec, recipeSlug) {
    const klucz = kluczWyboru(recipeSlug);

    const bezpiecznie = (dzialanie, zapasowo) => {
        try {
            return dzialanie();
        } catch {
            return zapasowo;
        }
    };

    return {
        czyWlaczony: () => bezpiecznie(() => pamiec?.getItem(klucz) === '1', false),
        zapamietaj: (wlaczony) => bezpiecznie(() => (wlaczony ? pamiec?.setItem(klucz, '1') : pamiec?.removeItem(klucz)), undefined),
    };
}

/**
 * Okablowanie przełącznika jednego dokumentu: ręczna zmiana włącza lub
 * wyłącza blokadę i zapamiętuje wybór, a zapamiętany wybór z poprzedniego
 * kroku od razu zaznacza przełącznik i prosi o blokadę na nowo.
 *
 * @returns {Promise<boolean>} wynik odtworzenia (false, gdy nie było czego
 *          odtwarzać albo przeglądarka odmówiła)
 */
export function podlaczPrzelacznik(checkbox, kontroler, pamiecWyboru) {
    checkbox.addEventListener('change', () => {
        pamiecWyboru.zapamietaj(checkbox.checked);

        if (checkbox.checked) {
            kontroler.wlacz();
        } else {
            kontroler.wylacz();
        }
    });

    if (!pamiecWyboru.czyWlaczony()) {
        return Promise.resolve(false);
    }

    checkbox.checked = true;

    return kontroler.wlacz();
}
