/*
 * Odliczanie JEDNEGO minutnika kroku — właściciel interwału (#2458).
 *
 * Wcześniej interwał i jego zatrzymanie żyły w domknięciu w `app.js`. Dodanie
 * czasu do trwającego (albo właśnie zakończonego) minutnika musi mieć TEGO
 * SAMEGO właściciela odliczania: bez drugiego interwału i bez drugiego alarmu,
 * a spóźniony callback starego odliczania nie może zakończyć nowego. Dlatego
 * odliczanie jest tu — z wstrzykniętym zegarem i planistą, więc da się je
 * sprawdzić w Node na kontrolowanym zegarze (`odliczanie-minutnika.test.mjs`).
 *
 * Czas liczymy na zegarze MONOTONICZNYM (`teraz()` = `performance.now()`), jak
 * w `minutnik-krok.js` (#751). Termin na zegarze ściennym trafia tylko do zapisu
 * w `sessionStorage` (`zapisz`) — po to, żeby przeżył przeładowanie strony.
 */

import {nowyTerminPoDodaniu, pozostaloSekund} from './minutnik-krok.js';

/**
 * @param {object} opcje
 * @param {() => number} opcje.teraz zegar monotoniczny (ms)
 * @param {{ustaw: (fn: () => void, ms: number) => unknown, wyczysc: (id: unknown) => void}} opcje.planista
 * @param {(sekundy: number) => void} opcje.pokaz odświeżenie licznika
 * @param {() => void} opcje.przyKoncu wywoływane RAZ po upływie terminu (alarm, komunikat)
 * @param {(terminMonotoniczny: number) => void} opcje.zapisz zapis terminu po zmianie (przeładowanie)
 * @param {() => void} opcje.usunZapis kasuje zapis (koniec, anulowanie)
 */
export function utworzOdliczanie({teraz, planista, pokaz, przyKoncu, zapisz, usunZapis}) {
    let interwal = null;
    let termin = null;
    // Numer odliczania: callback z wcześniejszego odliczania (np. spóźniony po
    // wyczyszczeniu interwału) widzi inny numer i nic nie robi.
    let generacja = 0;

    const zatrzymajInterwal = () => {
        if (interwal !== null) {
            planista.wyczysc(interwal);
        }
        interwal = null;
    };

    const uruchom = (nowyTermin) => {
        zatrzymajInterwal();
        termin = nowyTermin;
        generacja += 1;
        const moja = generacja;

        pokaz(pozostaloSekund(termin, teraz()));

        interwal = planista.ustaw(() => {
            if (moja !== generacja || interwal === null) {
                return;
            }

            const pozostalo = pozostaloSekund(termin, teraz());
            pokaz(pozostalo);

            if (pozostalo <= 0) {
                zatrzymaj();
                przyKoncu();
            }
        }, 1000);
    };

    const zatrzymaj = () => {
        zatrzymajInterwal();
        termin = null;
        generacja += 1;
        usunZapis();
    };

    return {
        uruchom,
        zatrzymaj,
        aktywne: () => interwal !== null,
        termin: () => termin,

        /**
         * Dodaje czas do trwającego odliczania (do pozostałego czasu) albo
         * wznawia zakończone (od teraz). Zawsze przez ten sam interwał:
         * poprzedni jest czyszczony, zanim powstanie nowy.
         *
         * @returns {'przedluzone'|'wznowione'}
         */
        dodaj(dodatkoweSekundy) {
            const trwalo = interwal !== null;
            const nowy = trwalo
                ? nowyTerminPoDodaniu(termin, teraz(), dodatkoweSekundy)
                : teraz() + dodatkoweSekundy * 1000;

            zapisz(nowy);
            uruchom(nowy);

            return trwalo ? 'przedluzone' : 'wznowione';
        },
    };
}
