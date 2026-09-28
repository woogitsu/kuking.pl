/*
 * KOPIA SZKICU Z SĄSIEDNIEGO FORMULARZA (issue #845).
 *
 * Karta wiadomości w panelu ma DWA osobne formularze — odpowiedź i stan
 * sprawy — i łączyć ich nie wolno (D-058: Enter na przycisku radio
 * w jednym wspólnym formularzu wysłałby list przez pomyłkę). Przeglądarka
 * wysyła jednak tylko pola zatwierdzonego formularza, więc zapis stanu gubił
 * wpisany, jeszcze niewysłany szkic odpowiedzi (i odwrotnie: wysłanie
 * odpowiedzi gubiło niezapisaną notatkę).
 *
 * Formularz niesie więc UKRYTE KOPIE pól sąsiada:
 *   <input type="hidden" name="odpowiedz" data-kopia-z="#selektor" disabled>
 * W chwili wysłania przepisujemy do kopii bieżącą wartość źródła i zdejmujemy
 * `disabled`. Kontroler tylko odsyła kopie z powrotem w `withInput()` —
 * NIGDY ich nie wykonuje: zapis stanu nie wysyła listu, wysyłka listu nie
 * zapisuje notatki.
 *
 * `disabled` w znaczniku jest celowe: bez skryptu kopia nie leci wcale,
 * zamiast lecieć pusta. Pusta kopia `reply_key` nadpisałaby w `old()` klucz
 * odpowiedzi i następna wysyłka odbiłaby się od walidacji.
 *
 * Zdarzenie `submit` przychodzi PRZED zbudowaniem listy pól do wysłania,
 * więc to, co tu wpiszemy, trafia do tego samego żądania.
 */
export function przepiszKopie(formularz, znajdz) {
    for (const kopia of formularz.querySelectorAll('input[data-kopia-z]')) {
        const zrodlo = znajdz(kopia.dataset.kopiaZ);

        if (!zrodlo) {
            // Źródła nie ma (np. radio bez zaznaczenia) — nic nie udajemy.
            kopia.disabled = true;
            continue;
        }

        kopia.value = zrodlo.value;
        kopia.disabled = false;
    }
}

if (typeof document !== 'undefined') {
    document.addEventListener('submit', (zdarzenie) => {
        if (zdarzenie.target instanceof HTMLFormElement) {
            przepiszKopie(zdarzenie.target, (selektor) => document.querySelector(selektor));
        }
    });
}
