/*
 * Licznik znaków „na żywo" pod polami z limitem serwera (issue #762).
 *
 * BEZ TEGO SKRYPTU pole nadal działa: `components/field.blade.php` rysuje
 * zawsze widoczne zdanie „Najwyżej N znaków.", a serwer i tak odrzuca za
 * długi tekst (`max:4000`). Ten plik tylko PODMIENIA to zdanie na bieżący
 * stan — ile zostało albo o ile za dużo — żeby nie trzeba było wysyłać
 * formularza, żeby się tego dowiedzieć.
 *
 * DLACZEGO LICZYMY PUNKTY KODOWE (`[...tekst].length`), NIE `.length`.
 * `String.prototype.length` liczy jednostki UTF-16 — emoji i inne znaki
 * spoza BMP zajmują DWIE. `mb_strlen()` po stronie PHP (walidacja
 * `max:4000`) liczy PUNKTY KODOWE, czyli jeden na taki znak. Bez tego
 * licznik pokazywałby inną liczbę niż ta, która naprawdę decyduje — i przy
 * tekście pełnym emoji potrafiłby stanowczo się mylić w obie strony.
 * Rozkładanie ciągu spreadem (`[...tekst]`) idzie po punktach kodowych,
 * tak samo jak `mb_strlen`; różni się od PHP dopiero przy znakach złożonych
 * z wielu punktów kodowych (np. emoji ze skórą), gdzie oba liczniki i tak
 * się rozjeżdżają w tę samą stronę względem „jednego widzianego znaku" —
 * to jest ograniczenie policzalne, nie cichy błąd.
 *
 * DLACZEGO NIE `maxlength`. Przeglądarka obcina wklejony tekst PRZY
 * `maxlength` — a #762 wprost tego zakazuje: człowiek ma zobaczyć, o ile
 * jest za długo, i sam skrócić, nie stracić bez ostrzeżenia koniec
 * wklejonego tekstu.
 *
 * DLACZEGO NIE `aria-live` NA KAŻDE NACIŚNIĘCIE KLAWISZA. Czytnik ekranu
 * czytałby wtedy „Zostało 3999 znaków", „Zostało 3998 znaków"... przy
 * każdej literze — zalew ogłoszeń, którego #762 też zakazuje. Ogłaszamy
 * WYŁĄCZNIE przejście przez granicę limitu (`role="alert"` na jeden update),
 * nie każdą zmianę liczby.
 */

/** Polska odmiana rzeczownika po liczebniku — te same trzy reguły co `App\Support\Odmiana::rzeczownik()` w PHP. */
function odmiana(n, jeden, kilka, wiele) {
    const mod10 = n % 10;
    const mod100 = n % 100;
    if (n === 1) return jeden;
    if (mod10 >= 2 && mod10 <= 4 && !(mod100 >= 12 && mod100 <= 14)) return kilka;
    return wiele;
}

/**
 * Treść licznika dla danej długości tekstu i limitu.
 *
 * Eksportowana osobno, żeby dało się sprawdzić testem jednostkowym (Node)
 * bez uruchamiania przeglądarki — patrz `licznik-znakow.test.mjs`.
 */
export function komunikatLicznika(dlugosc, limit) {
    const pozostalo = limit - dlugosc;

    if (pozostalo < 0) {
        const naddatek = -pozostalo;
        // BŁĄD MÓWI, CO ZROBIĆ (AGENTS.md, UX 50+): ile znaków skrócić, nie „za długo".
        return `Tekst jest za długi o ${naddatek} ${odmiana(naddatek, 'znak', 'znaki', 'znaków')}. Skróć go o tyle, żeby wysłać.`;
    }

    return `Zostało ${pozostalo} ${odmiana(pozostalo, 'znak', 'znaki', 'znaków')} z ${limit}.`;
}

/** Liczba punktów kodowych — patrz uzasadnienie na górze pliku. */
export function dlugoscWZnakach(tekst) {
    return [...tekst].length;
}

function setup(pole) {
    if (pole.dataset.licznikReady) return;

    const limit = Number(pole.dataset.licznik);
    const cel = pole.dataset.licznikCel ? document.getElementById(pole.dataset.licznikCel) : null;

    if (!Number.isFinite(limit) || limit <= 0 || !cel) return;

    pole.dataset.licznikReady = '1';
    let byloZaDlugo = false;

    function odswiez() {
        const dlugosc = dlugoscWZnakach(pole.value);
        const zaDlugo = dlugosc > limit;

        cel.textContent = komunikatLicznika(dlugosc, limit);
        cel.classList.toggle('field-help--blad', zaDlugo);

        // Ogłoszenie czytnikowi TYLKO przy przejściu w stan „za długo" —
        // nie przy każdej zmianie liczby, patrz uzasadnienie na górze pliku.
        if (zaDlugo && !byloZaDlugo) {
            cel.setAttribute('role', 'alert');
        } else if (!zaDlugo && byloZaDlugo) {
            cel.removeAttribute('role');
        }

        byloZaDlugo = zaDlugo;
    }

    pole.addEventListener('input', odswiez);
    odswiez();
}

function init() {
    document.querySelectorAll('[data-licznik]').forEach(setup);
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}
