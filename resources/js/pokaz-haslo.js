/*
 * „Pokaż hasło” / „Ukryj hasło” przy polach hasła (issue #948).
 *
 * BEZ TEGO SKRYPTU pole działa jak dotąd: `components/field.blade.php`
 * rysuje przycisk z atrybutem `hidden`, więc nikt nie zobaczy przycisku,
 * który nic nie robi (D-053). Skrypt odsłania przycisk i przełącza `type`
 * pola między `password` a `text`. Nic poza `type` się nie zmienia: `name`,
 * `id`, `autocomplete`, `aria-describedby` i wartość zostają, więc
 * menedżer haseł i wklejanie działają jak wcześniej.
 *
 * PRZED WYSŁANIEM FORMULARZA pole wraca do `password` — przeglądarka nie
 * zapamięta wtedy jawnego hasła jako zwykłego tekstu, a po powrocie
 * „Wstecz” pole nie stoi odsłonięte.
 *
 * Zmianę stanu ogłaszamy przez osobny obszar `aria-live`, bez przenoszenia
 * fokusu — fokus zostaje na przycisku.
 */

/**
 * Stan przełącznika dla „widoczne / ukryte”.
 *
 * Eksportowany osobno, żeby dało się sprawdzić testem jednostkowym (Node)
 * bez przeglądarki — patrz `pokaz-haslo.test.mjs`.
 */
export function stanPrzelacznika(widoczne) {
    return widoczne
        ? { type: 'text', napis: 'Ukryj hasło', pressed: 'true', komunikat: 'Hasło jest widoczne.' }
        : { type: 'password', napis: 'Pokaż hasło', pressed: 'false', komunikat: 'Hasło jest ukryte.' };
}

function ustaw(przycisk, pole, widoczne, ogloszenie) {
    const stan = stanPrzelacznika(widoczne);
    const napis = przycisk.querySelector('[data-pokaz-haslo-napis]');

    pole.type = stan.type;
    przycisk.setAttribute('aria-pressed', stan.pressed);
    if (napis) napis.textContent = stan.napis;
    if (ogloszenie) ogloszenie.textContent = stan.komunikat;
}

function setup(przycisk) {
    if (przycisk.dataset.pokazHasloReady) return;

    const pole = document.getElementById(przycisk.getAttribute('aria-controls') || '');
    if (!(pole instanceof HTMLInputElement) || pole.type !== 'password') return;

    przycisk.dataset.pokazHasloReady = '1';
    const ogloszenie = przycisk.parentElement?.querySelector('[data-pokaz-haslo-stan]') ?? null;

    przycisk.addEventListener('click', () => {
        ustaw(przycisk, pole, pole.type === 'password', ogloszenie);
    });

    // Przed wysłaniem i przed opuszczeniem strony pole wraca do zamaskowanego.
    pole.form?.addEventListener('submit', () => ustaw(przycisk, pole, false, null));
    window.addEventListener('pagehide', () => ustaw(przycisk, pole, false, null));

    przycisk.hidden = false;
}

function init() {
    document.querySelectorAll('[data-pokaz-haslo]').forEach(setup);
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}
