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
