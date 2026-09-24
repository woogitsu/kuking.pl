/*
 * Ostrzeżenie przed wyjściem z formularza z niezapisanymi zmianami (issue #899).
 *
 * GDZIE. Ekran „Dopisz szczegóły” ma dwa zwykłe odnośniki do kreatora
 * w trzech krokach. Kreator wczytuje przepis Z BAZY, więc to, co ktoś wpisał
 * tutaj i jeszcze nie zapisał, w kreatorze się nie pojawi. Odnośnik oznaczony
 * `data-niezapisane-formularz="<id formularza>"` i `data-niezapisane-ostrzezenie
 * ="<id ramki>"` przy zmienionym formularzu NIE przechodzi od razu, tylko
 * odsłania ramkę z pytaniem wpisanym w stronę — nie okno `confirm()`, które
 * dla osób 50+ bywa niewidoczne (`components/confirm-button.blade.php`).
 *
 * BEZ TEGO SKRYPTU odnośnik działa jak zawsze (żadnego martwego przycisku,
 * D-053), a zdanie przy nim mówi wprost, że kreator otworzy ostatnią zapisaną
 * wersję. Na niezmienionym formularzu skrypt nic nie pyta.
 *
 * CO ZNACZY „ZMIENIONY”. Porównujemy pola z wartościami, z którymi serwer
 * wyrenderował stronę (`defaultValue`, `defaultChecked`, `defaultSelected`),
 * a nie z migawką zrobioną po wczytaniu skryptu — przeglądarka potrafi
 * odtworzyć wpisany tekst po „Wstecz” ZANIM skrypt ruszy, i migawka uznałaby
 * go za zapisany. Wybrany plik liczy się zawsze jako zmiana. Formularz
 * odesłany z błędami (`data-niezapisane-od-serwera`) też: pokazuje wtedy
 * wpisane dane z `old()`, których w bazie jeszcze nie ma.
 */

/**
 * Czy którekolwiek pole różni się od stanu z serwera.
 *
 * Czysta funkcja na zwykłych obiektach z polami jak w DOM — sprawdzana
 * w Node bez przeglądarki (`niezapisane-zmiany.test.mjs`).
 */
export function czyPolaZmienione(pola) {
    for (const pole of pola) {
        if (pole.disabled) continue;
        const typ = (pole.type || '').toLowerCase();

        if (typ === 'hidden' || typ === 'submit' || typ === 'button' || typ === 'reset') continue;
        if (typ === 'file') {
            if (pole.files && pole.files.length > 0) return true;
            continue;
        }
        if (typ === 'checkbox' || typ === 'radio') {
            if (Boolean(pole.checked) !== Boolean(pole.defaultChecked)) return true;
            continue;
        }
        if (pole.options) {
            for (const opcja of pole.options) {
                if (Boolean(opcja.selected) !== Boolean(opcja.defaultSelected)) return true;
            }
            continue;
        }
        if ('defaultValue' in pole && pole.value !== pole.defaultValue) return true;
    }

    return false;
}

export function czyFormularzZmieniony(formularz) {
    if (formularz.dataset && formularz.dataset.niezapisaneOdSerwera !== undefined) return true;

    return czyPolaZmienione(formularz.elements);
}

function podlacz(odnosnik) {
    const formularz = document.getElementById(odnosnik.dataset.niezapisaneFormularz);
    const ramka = document.getElementById(odnosnik.dataset.niezapisaneOstrzezenie);
    if (!formularz || !ramka) return;

    odnosnik.addEventListener('click', (zdarzenie) => {
        // Ctrl/⌘/środkowy przycisk otwierają nową kartę — ta strona zostaje
        // z danymi, więc nie ma czego chronić.
        if (zdarzenie.defaultPrevented || zdarzenie.button !== 0
            || zdarzenie.metaKey || zdarzenie.ctrlKey || zdarzenie.shiftKey || zdarzenie.altKey) return;
        if (!czyFormularzZmieniony(formularz)) return;

        zdarzenie.preventDefault();
        ramka.hidden = false;
        ramka.focus();
    });

    ramka.querySelector('[data-niezapisane-zostan]')?.addEventListener('click', () => {
        ramka.hidden = true;
        odnosnik.focus();
    });
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('a[data-niezapisane-formularz]').forEach(podlacz);
}
