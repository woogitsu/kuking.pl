/*
 * „Drukuj przepis” (issue #765).
 *
 * Przycisk na stronie przepisu jest ZWYKŁYM ODNOŚNIKIEM do tej samej strony
 * z `?druk=1`, gdzie stoi instrukcja „naciśnij Ctrl+P / menu → Drukuj”.
 * Bez skryptu (albo gdy się nie dociągnął) człowiek dostaje więc zdanie
 * mówiące, co zrobić, a nie martwy przycisk (AGENTS.md §5, D-053).
 *
 * Ze skryptem kliknięcie od razu otwiera okno drukowania przeglądarki.
 */

/**
 * Czy to kliknięcie ma otworzyć okno drukowania zamiast przejść pod adres.
 * Klik z Ctrl/Cmd/Shift albo środkowym przyciskiem zostawiamy przeglądarce
 * (nowa karta), tak samo gdy przeglądarka nie umie drukować.
 */
export function czyDrukowac(zdarzenie, okno) {
    if (typeof okno?.print !== 'function') return false;
    if (zdarzenie.defaultPrevented) return false;
    if ((zdarzenie.button ?? 0) !== 0) return false;
    if (zdarzenie.ctrlKey || zdarzenie.metaKey || zdarzenie.shiftKey || zdarzenie.altKey) return false;

    return zdarzenie.target?.closest?.('[data-drukuj-przepis]') != null;
}

if (typeof document !== 'undefined') {
    document.addEventListener('click', (zdarzenie) => {
        if (!czyDrukowac(zdarzenie, window)) return;

        zdarzenie.preventDefault();
        window.print();
    });
}
