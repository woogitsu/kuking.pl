{{--
    Styl paczki z danymi. Wszystko w jednym <style> w każdym pliku, świadomie.

    Powód: te pliki mają się otwierać z pendrive'a, bez internetu i bez Kuking,
    także za dziesięć lat. Osobny plik CSS jest jedną rzeczą więcej, którą można
    zgubić przy kopiowaniu — a wtedy strona wygląda jak surowy tekst.

    Rozmiary są wzięte ze standardu UX 50+ (docs/UX_50_PLUS.md): tekst
    podstawowy 20 px, duże odstępy, jedna kolumna, wysoki kontrast.
--}}
<style>
    :root {
        --tlo: #F3F4F1;
        --karta: #FFFFFF;
        --tekst: #151714;
        --tekst-jasny: #555E53;
        --ramka: #DDE0D8;
        --marka: #BE3025;
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        padding: 24px 16px 64px;
        background: var(--tlo);
        color: var(--tekst);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans", Arial, sans-serif;
        font-size: 1.25rem;
        line-height: 1.65;
    }

    .strona {
        max-width: 44rem;
        margin: 0 auto;
        overflow-wrap: anywhere;
    }

    h1 {
        font-size: 34px;
        line-height: 1.25;
        margin: 0 0 8px;
    }

    h2 {
        font-size: 26px;
        line-height: 1.3;
        margin: 40px 0 12px;
        padding-bottom: 8px;
        border-bottom: 2px solid var(--ramka);
    }

    h3 {
        font-size: 22px;
        margin: 28px 0 8px;
    }

    p { margin: 0 0 16px; }

    a {
        color: var(--marka);
        text-decoration: underline;
    }

    .naglowek {
        background: var(--tekst);
        color: #FFFFFF;
        border-radius: 24px;
        padding: 24px;
        margin-bottom: 28px;
    }

    .naglowek .podpis { color: #CBD0C6; }

    a:focus-visible { outline: 3px solid #155EEF; outline-offset: 4px; }

    /* Cel dotknięcia 48 px dla odnośników, które SĄ osobną akcją, a nie
       słowem w zdaniu. `.akcja` to akapit, którego całą treścią jest jeden
       odnośnik — tak stoi „Otwórz katalog ze zdjęciami" w spisie treści.
       Bez tej klasy tamten odnośnik miał zmierzone 22 px wysokości, czyli
       mniej niż produktowe 48 px (AGENTS.md §5) i mniej niż 24 px z WCAG 2.2
       AA 2.5.8 — a wyjątek „inline" tam nie działa, bo to nie jest odnośnik
       wewnątrz zdania.

       Dlaczego klasa na akapicie, a nie selektor `p > a:only-child`:
       `:only-child` liczy RODZEŃSTWO ELEMENTÓW, a nie tekst. Zdanie
       „Wróć do <a>spisu treści</a>." ma jedno dziecko-element, więc taki
       selektor łapie też odnośnik w środku zdania — zmierzone: wysokość
       tego odnośnika rosła z 20 px do 48 px i rozpychała wiersz stopki.
       Odnośnik w zdaniu ma zostać słowem. */
    .spis a, .powrot a, .akcja a { display: inline-block; min-height: 48px; padding-block: 8px; }

    .podpis {
        color: var(--tekst-jasny);
        font-size: 1.125rem;
        margin: 0;
    }

    .karta {
        background: var(--karta);
        border: 1px solid var(--ramka);
        border-radius: 24px;
        padding: 20px 24px;
        margin: 0 0 20px;
    }

    ul, ol { padding-left: 28px; margin: 0 0 16px; }
    li { margin-bottom: 10px; }

    .spis li { margin-bottom: 14px; font-size: 21px; }

    .skladniki { list-style: none; padding-left: 0; }
    .skladniki li {
        padding: 10px 0;
        border-bottom: 1px solid var(--ramka);
    }
    .skladniki .ile { font-weight: bold; }

    .kroki li { margin-bottom: 22px; }

    img.zdjecie {
        display: block;
        max-width: 100%;
        height: auto;
        border-radius: 24px;
        border: 1px solid var(--ramka);
        margin: 0 0 12px;
    }

    .fakty { color: var(--tekst-jasny); font-size: 18px; margin: 0 0 20px; }
    .fakty span { margin-right: 18px; white-space: normal; }

    /* Ostrzeżenie o niekompletnej paczce. Osobne od `.karta`, bo ma się
       RZUCAĆ W OCZY — człowiek czyta ten plik raz i musi zauważyć, że czegoś
       brakuje, zanim skasuje konto. Kolor to sam dodatek: nośnikiem jest
       samo zdanie i gruba lewa krawędź, więc komunikat działa też
       na wydruku czarno-białym. */
    .uwaga {
        background: #FCEACB;
        border: 1px solid #7A5C10;
        border-left: 8px solid #7A5C10;
        border-radius: 24px;
        padding: 20px 24px;
        margin: 0 0 20px;
        color: #4A3607;
    }

    .uwaga p { margin: 0; }

    .plakietka {
        display: inline-block;
        background: #FCEACB;
        color: #6B4E0C;
        border: 1px solid #7A5C10;
        border-radius: 6px;
        padding: 2px 10px;
        font-size: 1.125rem;
        font-family: Arial, Helvetica, sans-serif;
    }

    .stopka {
        margin-top: 48px;
        padding-top: 20px;
        border-top: 2px solid var(--ramka);
        color: var(--tekst-jasny);
        font-size: 1.125rem;
    }

    .powrot { font-size: 1.25rem; }

    /* Paczka działa poza portalem, więc korzysta z wyboru systemowego.
       Ograniczenie do screen zachowuje jasne kolory wydruku. */
    @media screen and (prefers-color-scheme: dark) {
        :root {
            --tlo: #151714;
            --karta: #222620;
            --tekst: #F4F5F1;
            --tekst-jasny: #CBD0C6;
            --ramka: #373D34;
            --marka: #FF9586;
        }
        .naglowek { background: #222620; color: #F4F5F1; }
        .naglowek .podpis { color: #CBD0C6; }
        a:focus-visible { outline-color: #6EA8FF; }
    }

    @media print {
        body { background: #FFFFFF; font-size: 12pt; }
        .karta { border: none; padding: 0; }
        .naglowek { background: #FFFFFF; color: #151714; padding: 0 0 20px; }
        .naglowek .podpis { color: #555E53; }

        /* Nagłówek nie zostaje sam na dole kartki.
           Zmierzone na wydruku A4 przed tą regułą (pdftotext, strona po
           stronie): w przepisie „Pierogi ruskie" nagłówek „Składniki"
           kończył stronę 1, a pierwszy składnik zaczynał stronę 2; tak samo
           „Jak to zrobić" kończyło stronę 2, a krok 1 zaczynał stronę 3. */
        h1, h2, h3 { break-after: avoid; page-break-after: avoid; }

        /* Krok nie rozpada się na dwie kartki.
           Zmierzone przed tą regułą: w przepisie „Rosół z kury" tekst kroku 2
           wychodził na stronie 2, a zdjęcie TEGO SAMEGO kroku na stronie 3 —
           czyli przy garnku człowiek ma instrukcję na jednej kartce,
           a obrazek do niej na drugiej. To samo dotyczy kart z komentarzem
           i wpisem oraz pozycji listy składników. */
        .kroki li, .skladniki li, .karta, .uwaga, img.zdjecie {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        /* Zdjęcie nie zajmuje całej kartki.
           Zmierzone: skan z zeszytu (1200 × 1600) schodził na wydruku do
           około 24 cm wysokości, więc razem z nagłówkiem nie mieścił się
           na stronie i spychał wszystko dalej — przepis rósł z 5 kartek do
           6, a jedna z nich zostawała zapełniona w kilkunastu procentach.
           16 cm to nadal duże zdjęcie, a na kartce zostaje miejsce na tekst.
           Szerokość liczy się sama z proporcji (`height: auto` wyżej). */
        img.zdjecie { max-height: 16cm; }
    }
</style>
