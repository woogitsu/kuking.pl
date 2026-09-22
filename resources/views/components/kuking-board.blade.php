{{--
    Tablica „kuKINGi na dziś".

    Kilka osób i kilka dań wartych zobaczenia dzisiaj. To NIE jest ranking —
    nigdzie nie pokazujemy liczby obserwujących ani niczego, co wygląda
    na miarę popularności.

    Stopka jest częścią funkcji, nie ozdobą: mówi wprost, że to nie jest
    tabela wyników. Dlatego wolno ją ZASTĄPIĆ, ale nie wolno jej usunąć —
    bez niej krótka lista osób i dań zaczyna wyglądać jak ranking, czyli
    dokładnie to, czego zabrania AGENTS.md §12.

    DO 11 WRZEŚNIA 2026 STAŁO TU „Jutro będzie tu ktoś inny." — I TO ZDANIE
    OBIECYWAŁO PEWNOŚĆ, KTÓREJ NIE MA.

    Mechanizm zmiany ISTNIEJE, ale jest ręczny: gospodarz układa tablicę na
    dziś w panelu `/kuking-na-dzis` (`routes/web.php`, trasy `admin.daily-board`),
    a wiersze `daily_picks` powstają w `DailyBoardController::update()`. Nie ma
    natomiast żadnego automatu, który by tę tablicę odświeżał — ani zadania
    w `routes/console.php`, ani komendy w `app/Console/Commands/`. Gdy
    gospodarz nic nie wybierze, wchodzi wariant zapasowy
    (`DailyBoard::automaticPosts()` i `DailyBoard::peopleToFollow()`), a ten
    sortuje po dacie publikacji malejąco — więc w wolny dzień jutro stoją tu
    dokładnie te same osoby co dziś. Przy starcie opisanym
    w `docs/product/COLD_START.md` to jest reguła, nie wyjątek.

    Nowe zdanie robi tę samą robotę (to nie jest tabela wyników) i nie
    obiecuje niczego o jutrze.

    Teksty: docs/brand/COPY_STYLE.md §5

    ══════════════════════════════════════════════════════════════════════
     UKŁAD: JEDNA KOLUMNA ZDJĘĆ NA OBIE LISTY (zgłoszenie właściciela,
     „bez wyglądu, układu, żadnej symetrii")
    ══════════════════════════════════════════════════════════════════════

    Zmierzone przed poprawką na `/` przy oknie 1512 px (tablica szeroka na
    992 px): osoba zaczynała tekst przy `x = 345`, danie ZE zdjęciem przy
    `x = 389`, danie BEZ zdjęcia przy `x = 249`, pasek miniatur przy `x = 249`,
    a „Załóż konto, żeby obserwować" wisiało przy prawej krawędzi, 600 px od
    opisu, do którego należy. Cztery różne krawędzie w jednej sekcji.

    Historyczny układ list (nadal używany poza stroną powitalną) ma kształt:

        [ zdjęcie 120 px ] [ tekst ]
        [ pasek miniatur — cała szerokość, przy lewej krawędzi ]
        [ akcja — cała szerokość, przy lewej krawędzi ]

    Awatar osoby i zdjęcie dania to ta sama kolumna (`--tablica-zdjecie`
    w `app.css`), więc tekst w obu listach zaczyna się przy tym samym `x`,
    a wszystko, co dostaje własny wiersz, licuje z lewą krawędzią karty.
    Zdjęcie dania urosło przy okazji z 96 na 120 px — w serwisie, który stoi
    na zdjęciach, danie było mniejsze od przycisku obok siebie.

    `graSlowem` wybiera nazwę sekcji. Na stronie powitalnej pozostaje
    „Co się dziś gotuje”. Historyczny limit jednej gry słownej na ekran
    został odwołany przez właściciela; aktualną zasadę określa AGENTS.md §11.

    `wKarcie` — TABLICA JEST KARTĄ TYLKO TAM, GDZIE STOI OBOK INNYCH KART
    W szynie (`szyna-startowa.blade.php`, `/szukaj`, a od 11 września także
    `/odkryj`) karta jest na miejscu: tablica sąsiaduje tam z „Moim zeszytem"
    i z kartami wpisów, więc białe tło z obwódką mówi, gdzie się kończy.
    Na stronie powitalnej jest odwrotnie — hierarchię buduje tam tło PASA
    (`.pas`, `pages/landing.blade.php`), a tablica była JEDYNĄ sekcją z kartą
    w środku pasa, czyli kartą w karcie. Domyślnie pytamy więc o trasę, ale
    wywołanie może to rozstrzygnąć wprost: `:wKarcie="false"`.
--}}
@props(['people', 'posts', 'notes' => [], 'graSlowem' => true, 'wKarcie' => null])

@php
    $pusta = $people->isEmpty() && $posts->isEmpty();
    $wKarcie ??= ! request()->routeIs('landing');
    $naPowitalnej = request()->routeIs('landing') && ! $wKarcie;
@endphp

{{-- Warstwa powierzchni i `mb-6` TYLKO tam, gdzie tablica stoi obok innych
     powierzchni. W pasie na stronie powitalnej tło i wcięcie daje sam pas —
     patrz `wKarcie` wyżej.

     `sekcja-strony`, nie `card`: tablica dnia jest blokiem strony, a nie
     rzeczą, po którą człowiek tu przyszedł (docs/design/ROLE_KART.md, rola 3).
     Karty treści są dopiero w środku — bez tego rozdzielenia mielibyśmy
     kartę w karcie o tym samym wyglądzie. --}}
<section @class(['sekcja-strony' => $wKarcie, 'kuking-board', 'landing-tablica' => $naPowitalnej, 'marka-tablica' => $wKarcie, 'mb-6' => $wKarcie]) aria-labelledby="kuking-na-dzis">
    <div @class(['marka-tablica-wstep' => $wKarcie])>
    @if($wKarcie || $naPowitalnej)<p class="nadtytul">Z innych kuchni</p>@endif
    <h2 @class(['mt-0', 'text-title-lg' => ! $wKarcie]) id="kuking-na-dzis">
        @if($wKarcie)
            Co dobrego u innych?
        @elseif($graSlowem)
            <x-kuking-word forma="i" /> na dziś
        @else
            Co się dziś gotuje
        @endif
    </h2>
    @if($wKarcie)
        <p>Zdjęcia obiadów, rodzinne przepisy i kilka słów z codziennego gotowania.</p>
        <a href="{{ route('discover') }}">Zobacz dania i przepisy</a>
    @endif
    </div>

    @if($pusta)
        <p class="meta mb-0">
            Dziś jeszcze nikogo nie wybraliśmy.
            Zajrzyj do <a href="{{ route('discover') }}">Świeżo z <x-kuking-word /></a>.
        </p>
    @else
        @unless($wKarcie)<p class="meta">{{ $naPowitalnej ? 'Codzienne gotowanie, zdjęcia i pomysły z domowych kuchni.' : 'Kilka osób i kilka dań, które dziś warto zobaczyć.' }}</p>@endunless

        {{-- OSOBY OBOK DAŃ TAM, GDZIE SIĘ MIESZCZĄ (issue #365).

             Historia #365; nowy wariant powitalny #557 rozdziela te listy.
             Zgłoszenie właściciela: „w »co się dziś gotuje« można zrobić dwie
             kolumny". `docs/UX_50_PLUS.md` §Desktop dopuszcza to wprost
             („maks. 2 główne kolumny"); zakazana jest ŚCIANA MAŁYCH KAFELKÓW,
             więc zdjęcia zostają na 120 px i karty się nie kurczą.

             O dwóch kolumnach decyduje SZEROKOŚĆ SAMEJ TABLICY, nie szerokość
             okna — ta sama tablica stoi w szynie (352 px), w kolumnie `/odkryj`
             (720 px) i w pasie na stronie powitalnej (992 px). Zapytanie
             o kontener (`@container` przy `.kuking-board` w app.css) pyta więc
             o to, co naprawdę rozstrzyga; zapytanie o okno dałoby dwie kolumny
             także w szynie, w której jedna karta ma 310 px. --}}
        <div class="kuking-board-kolumny">
        {{-- Na powitalnej najpierw zdjęcia dań, potem ich autorzy. To kolejność DOM,
             nie wizualne przestawienie odnośników przez CSS (#557). --}}
        @if($naPowitalnej)
            @include('components.kuking-board.posts')
            @include('components.kuking-board.people')
        @else
            @include('components.kuking-board.people')
            @include('components.kuking-board.posts')
        @endif
        </div>

        <p class="meta kuking-board-footer">Tu nie ma rankingu. Pokazujemy różne osoby, nie najlepsze.</p>
    @endif
</section>
