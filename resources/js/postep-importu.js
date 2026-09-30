/*
 * Ekran postępu odczytu przepisu (V2, D-298) — samoodświeżanie bloku.
 *
 * BEZ SKRYPTU: blok ma odnośnik „Sprawdź, czy już gotowe”, który przeładowuje
 * stronę. Ten moduł jest wygodą (D-053), nie warunkiem działania.
 *
 * ZE SKRYPTEM: co 5 s pobieramy TEN SAM blok z serwera (`?fragment=1`)
 * i podmieniamy jego zawartość. Kontener ma `aria-live="polite"`, więc
 * czytnik ekranu ogłasza nowy stan. Gdy stan jest końcowy
 * (`data-koncowy="1"`), odświeżanie się zatrzymuje. Tylko na tym ekranie —
 * żadnego `wire:poll` na ekranach często odwiedzanych (AGENTS.md §3).
 */

export const CO_ILE_MS = 5000;

export function czyKoncowy(korzen) {
    return korzen.querySelector('[data-koncowy="1"]') !== null;
}

export function podlaczPostepImportu(dokument = document, okno = window) {
    const blok = dokument.querySelector('[data-postep-importu]');

    if (!blok || typeof okno.fetch !== 'function' || czyKoncowy(blok)) {
        return null;
    }

    const adres = blok.getAttribute('data-adres');

    // KOLEJNOŚĆ ODPOWIEDZI (#2328). Przy wolnym łączu odpowiedź sprzed 5 s
    // potrafi wrócić PO nowszej. Bez tej straży opóźniona odpowiedź „w toku”
    // nadpisywała już pokazane „gotowe” i ekran cofał się do starszego stanu.
    // Każde żądanie dostaje numer; stosujemy tylko odpowiedź nowszą od
    // ostatnio pokazanej, a po stanie końcowym — już żadnej.
    let numer = 0;
    let pokazany = 0;
    let koniec = false;

    const zegar = okno.setInterval(() => {
        if (koniec) {
            return;
        }

        numer += 1;
        const ten = numer;

        okno.fetch(adres, {headers: {'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'})
            .then((odpowiedz) => (odpowiedz.ok ? odpowiedz.text() : null))
            .then((html) => {
                if (html === null || koniec || ten < pokazany) {
                    return;
                }

                pokazany = ten;
                blok.innerHTML = html;

                if (czyKoncowy(blok)) {
                    koniec = true;
                    okno.clearInterval(zegar);
                }
            })
            .catch(() => {});
    }, CO_ILE_MS);

    return zegar;
}

if (typeof document !== 'undefined') {
    podlaczPostepImportu();
}
