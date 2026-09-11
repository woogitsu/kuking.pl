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

    ═══ FACEBOOK DOSZEDŁ 11.09 I NIE JEST KOPIĄ GOOGLE'A ═══

    Do 11.09 stało tu, że Facebooka nie ma, bo nie ma go w kodzie, i że
    dorysowanie przycisku byłoby MARTWYM PRZYCISKIEM (D-053). Kod powstał
    (issue #259), więc Facebook wchodzi tu jedną linijką w tablicy
    `$dostawcy` — dokładnie tak, jak ta lista zapowiadała.

    Z ekranu wyglądają identycznie i to jest w porządku: człowiek ma wybrać
    serwis, który zna, a nie zrozumieć różnicę. Różnica jest po naszej
    stronie i jest duża — **Facebook nie mówi, czy adres e-mail jest
    potwierdzony**, więc tamta droga nigdy nie łączy się z istniejącym
    kontem po adresie i zakłada konto z adresem NIEPOTWIERDZONYM (D-098,
    `FacebookLoginController`). Widok o tym nie musi wiedzieć; wie o tym
    kontroler i wie baza.

    Czego tu nadal NIE MA: trzeciego dostawcy. Gdy kiedyś dojdzie, dojdzie
    tak samo — jedną linijką i znakiem w `x-logo-dostawcy`.

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
@props(['naglowek' => 'Masz konto Google albo Facebooka? Wejdź jednym kliknięciem'])

@php
    /**
     * Dostawcy, których droga NAPRAWDĘ dziś działa. Kolejność w tablicy jest
     * kolejnością na ekranie: Google po lewej, Facebook po prawej — prośba
     * właściciela z 11.09, wypisana tutaj, żeby następna osoba nie
     * przestawiła tego „dla porządku alfabetycznego".
     *
     * Każdy wpis wchodzi POD WARUNKIEM, że jego droga działa, i pyta o to
     * JEDNO miejsce na dostawcę (`App\Support\Google::dziala()`,
     * `App\Support\Facebook::dziala()`) — to samo, o które pyta kontroler.
     * Dzięki temu nie da się dojść do stanu „przycisk jest, droga nie
     * działa", czyli do martwego przycisku z D-053. Bez kluczy dostawcy
     * jego przycisku po prostu nie ma, a gdy nie działa ŻADEN — nie ma
     * całego bloku (patrz `@if` niżej).
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

    if (\App\Support\Facebook::dziala()) {
        $dostawcy[] = [
            'znak' => 'facebook',
            'nazwa' => 'Facebooka',
            'napis' => 'Wejdź kontem Facebooka',
            'adres' => route('facebook.start'),
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
    <div class="sekcja-strony mt-6">
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
            nie bierzemy listy znajomych ani kontaktów, nie mamy dostępu do Twojej
            poczty i nigdy nic nie napiszemy na Twojej tablicy.
        </p>
    </div>
@endif
