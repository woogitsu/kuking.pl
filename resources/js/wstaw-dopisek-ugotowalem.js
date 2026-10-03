/*
 * #2857: wstaw prywatny dopisek w bieżącym formularzu. Nawigacja GET
 * gubiłaby niewysłany tekst i FileList, których nie można odtworzyć z serwera.
 */
export function podlaczDopisek(przycisk) {
    const formularz = przycisk.closest('form');
    const pole = formularz?.elements.namedItem('changes_note');
    const potwierdzenie = formularz?.querySelector('[data-dopisek-potwierdzenie]');
    const status = formularz?.querySelector('[data-dopisek-status]');
    const instrukcja = formularz?.querySelector('[data-dopisek-recznie]');
    if (!(pole instanceof HTMLTextAreaElement) || !potwierdzenie || !status) return;

    const wstaw = () => {
        pole.value = przycisk.dataset.dopisekTresc ?? '';
        pole.dispatchEvent(new Event('input', {bubbles: true}));
        potwierdzenie.hidden = true;
        status.hidden = false;
        pole.focus();
    };

    przycisk.hidden = false;
    if (instrukcja) instrukcja.hidden = true;
    przycisk.addEventListener('click', () => {
        if (pole.value.trim() !== '') {
            potwierdzenie.hidden = false;
            potwierdzenie.focus();
            return;
        }
        wstaw();
    });
    potwierdzenie.querySelector('[data-dopisek-zastap]')?.addEventListener('click', wstaw);
    potwierdzenie.querySelector('[data-dopisek-zostaw]')?.addEventListener('click', () => {
        potwierdzenie.hidden = true;
        przycisk.focus();
    });
    potwierdzenie.addEventListener('keydown', (zdarzenie) => {
        if (zdarzenie.key === 'Escape' && !potwierdzenie.hidden) {
            zdarzenie.preventDefault();
            potwierdzenie.hidden = true;
            przycisk.focus();
        }
    });
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-wstaw-dopisek]').forEach(podlaczDopisek);
}
