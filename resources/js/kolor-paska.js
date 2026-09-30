// Kolor paska przeglądarki i ekranu startowego PWA idzie za motywem (#2267).
//
// Serwer wypisuje `<meta name="theme-color">` od razu w kolorze motywu
// (`components/layout.blade.php`), a oba kolory trzyma w `data-jasny`
// i `data-ciemny` — tu nie ma drugiej kopii palety. Ta funkcja jest
// potrzebna tylko przy podglądzie „Dopasuj wygląd", który przełącza motyw
// bez przeładowania strony: bez niej jasna strona dostawała ciemny pasek
// (i odwrotnie) aż do następnego wejścia.
export function ustawKolorPaska(dokument, motyw) {
    const meta = dokument.querySelector('meta[name="theme-color"]');
    if (!meta) return;
    const kolor = motyw === 'dark' ? meta.dataset.ciemny : meta.dataset.jasny;
    if (kolor) meta.setAttribute('content', kolor);
}
