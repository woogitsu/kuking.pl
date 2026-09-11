{{--
    RZĄD WEJŚĆ KONTEM U DOSTAWCY ZEWNĘTRZNEGO — jeden blok na wszystkich
    (issue #258 i #259, D-069, D-098).

    DLACZEGO TEN KOMPONENT POWSTAŁ. Właściciel zgłosił 11.09 dwie rzeczy
    naraz: że wejście kontem Google jest „słabo widoczne, na samym dole",
    i że chce Google oraz Facebooka OBOK SIEBIE w jednej linii, bo
    „większość ma fb i google i łatwiej im będzie". Pierwsza rzecz to
    kolejność na stronie i jest zrobiona — ten blok stoi teraz NAD
    formularzem. Druga wymaga listy dostawców, a nie jednego wpisanego
    na sztywno, i to jest ten komponent.

    ═══ CZEGO TU DZIŚ NIE MA I DLACZEGO ═══

    FACEBOOKA NIE MA, BO NIE MA GO W KODZIE. Sprawdzone 11.09:
    `app/Support/` ma tylko `Google.php`, `routes/web.php` nie ma ani jednej
    trasy `wejdz/facebook`, `TozsamoscZewnetrzna` zna jedną stałą
    (`DOSTAWCA_GOOGLE`), a ograniczenie w bazie dopuszcza jedną wartość —
    `private const DOSTAWCY = ['google']` w migracji
    `create_tozsamosci_zewnetrzne_table`.

    Dorysowanie przycisku Facebooka byłoby więc MARTWYM PRZYCISKIEM, a tego
    zakazuje D-053 — i zakazuje słusznie: 65-latka, która kliknie i wróci
    na tę samą stronę bez słowa wyjaśnienia, nie próbuje drugi raz. Znak
    Facebooka też świadomie nie wchodzi do `x-logo-dostawcy`: zasób, którego
    nic nie renderuje, przy następnym czytaniu wygląda jak zapomniany kod.

    Facebook wejdzie tu jedną linijką w tablicy `$dostawcy` razem z PR-em
    z issue #259 — i to jest cały sens tej listy.

    ═══ JEDEN DOSTAWCA CZY DWA — UKŁAD ROBI TO SAM ═══

    `.form-actions` jest już `display: flex` z `flex-wrap: wrap`, więc
    jeden przycisk zajmuje wiersz, dwa stają obok siebie, a przy wąskim
    ekranie albo dużej czcionce zawijają się na dwa wiersze. ZERO nowego
    CSS-u — i to nie jest oszczędność, a wymóg: w widokach nie ma ani
    jednego atrybutu `style=` (issue #107), a polityka bezpieczeństwa nie
    ma `unsafe-inline` dla stylów, więc styl w linii i tak nie zadziałałby.

    ═══ UX 50+ (AGENTS.md §5, docs/UX_50_PLUS.md) ═══

    - ZNAK MARKI NIGDY NIE JEST SAM. Na przycisku stoi zdanie „Wejdź kontem
      Google", a znak jest przed nim — kto go nie kojarzy, czyta napis.
    - Przycisk to zwykły odnośnik w klasie `.btn`, więc ma te same 48 px
      wysokości i ten sam rozmiar tekstu co „Załóż konto".
    - Nad przyciskami stoi zdanie, PO CO to jest i CO SIĘ STANIE po
      kliknięciu. Przeniesienie na obcą domenę bez ostrzeżenia wygląda dla
      tej grupy jak phishing, przed którym ostrzegają banki.
    - Pod przyciskami stoi zdanie o tym, czego NIE bierzemy — bo pierwsze
      pytanie po „zaloguj się przez Google" brzmi „a co oni o mnie zobaczą".
    - Bez JavaScriptu działa w całości: odnośnik i przekierowania po stronie
      serwera. Ta droga jest jedyną na tych ekranach, która NIE potrzebuje
      skryptu (Turnstile go potrzebuje, D-050) — argument za nią, nie przeciw.

    ═══ NIC SIĘ NIE RENDERUJE, GDY ŻADNA DROGA NIE DZIAŁA ═══

    Pytanie „czy ta droga działa" ma JEDNO miejsce na dostawcę
    (`App\Support\Google::dziala()`) i odpowiada tak samo widokowi
    i kontrolerowi — więc nie da się dojść do stanu „przycisk jest, droga
    nie działa".
--}}
@props(['naglowek' => 'Masz konto Google? Wejdź jednym kliknięciem'])

@php
    /**
     * Dostawcy, których droga NAPRAWDĘ dziś działa. Kolejność w tablicy jest
     * kolejnością na ekranie: Google pierwszy, bo jest jedyny — a gdy dojdzie
     * Facebook (#259), właściciel chciał go po prawej.
     */
    $dostawcy = [];

    if (\App\Support\Google::dziala()) {
        $dostawcy[] = [
            'znak' => 'google',
            'nazwa' => 'Google',
            'napis' => 'Wejdź kontem Google',
            'adres' => route('google.start'),
        ];
    }

    /**
     * ZDANIE „PRZENIESIEMY CIĘ NA STRONĘ…" SKŁADA SIĘ TUTAJ, NIE W WIDOKU.
     *
     * Pierwsza wersja miała to w linii: `@if(count($dostawcy) === 1)Google
     * @else dostawcy@endif`. NIE SKOMPILOWAŁO SIĘ i nie zgłosiło tego jako
     * błędu szablonu, tylko jako błąd składni PHP-a przy renderowaniu —
     * `ParseError: unexpected end of file, expecting "elseif" or "else" or
     * "endif"`, w KOMPILACIE, z numerem linii, który nic nie mówi o źródle.
     *
     * Przyczyna: Blade szuka dyrektyw wzorcem zaczynającym się od `\B@`,
     * czyli `@` NIE MOŻE stać zaraz po znaku słowa. W `dostawcy@endif`
     * przed `@` stoi litera, więc `@endif` zostaje zwykłym tekstem,
     * a otwarte `if` nie ma zamknięcia. Objaw jest o dwa poziomy dalej niż
     * przyczyna — dlatego stoi to tu opisane, a nie tylko naprawione.
     *
     * Wniosek na przyszłość: dyrektywy Blade nie przyklejamy do tekstu.
     * Gdy zdanie ma się zmieniać od warunku, warunek liczy PHP.
     */
    $naStrone = count($dostawcy) === 1
        ? 'na stronę '.$dostawcy[0]['nazwa']
        : 'na stronę wybranego serwisu';
@endphp

@if($dostawcy !== [])
    <div class="card mt-6">
        <h2>{{ $naglowek }}</h2>
        <p>
            Nie musisz wymyślać ani pamiętać hasła. Przeniesiemy Cię
            {{ $naStrone }}, tam potwierdzisz, że to Ty, i wrócisz do Kuking.
        </p>
        <p class="form-actions wejscia-dostawcow">
            @foreach($dostawcy as $dostawca)
                <a class="btn btn-secondary" href="{{ $dostawca['adres'] }}">
                    <x-logo-dostawcy :nazwa="$dostawca['znak']" />
                    {{ $dostawca['napis'] }}
                </a>
            @endforeach
        </p>
        <p class="meta">
            Dostajemy tylko Twój adres e-mail i imię. Nie bierzemy zdjęcia,
            nie bierzemy listy kontaktów i nie mamy dostępu do Twojej poczty.
        </p>
    </div>
@endif
