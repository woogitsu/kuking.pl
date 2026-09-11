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

    Teraz obie listy mają ten sam kształt wiersza:

        [ zdjęcie 120 px ] [ tekst ]
        [ pasek miniatur — cała szerokość, przy lewej krawędzi ]
        [ akcja — cała szerokość, przy lewej krawędzi ]

    Awatar osoby i zdjęcie dania to ta sama kolumna (`--tablica-zdjecie`
    w `app.css`), więc tekst w obu listach zaczyna się przy tym samym `x`,
    a wszystko, co dostaje własny wiersz, licuje z lewą krawędzią karty.
    Zdjęcie dania urosło przy okazji z 96 na 120 px — w serwisie, który stoi
    na zdjęciach, danie było mniejsze od przycisku obok siebie.

    `graSlowem` — DAWKOWANIE, NIE OZDOBA (issue #38)
    `docs/brand/COPY_STYLE.md` §2 dopuszcza grę słowem „kuKING" NAJWYŻEJ RAZ
    NA EKRAN. Na stronie powitalnej pierwsze miejsce jest już zajęte przez
    przycisk „Zostań kuKINGiem", który §8 przypisuje tam wprost — więc tablica
    dostaje na tym jednym ekranie nagłówek zapasowy „Co się dziś gotuje".
    To nie jest nowy tekst: §5 podaje go jako gotową alternatywę („nie odmienia
    słowa wcale, problem znika u źródła", D-013). Wszędzie indziej — /home,
    /odkryj, /szukaj — tablica jest jedynym takim miejscem na ekranie
    i zostaje przy nazwie „kuKINGi na dziś".

    `wKarcie` — TABLICA JEST KARTĄ TYLKO TAM, GDZIE STOI OBOK INNYCH KART
    W szynie (`szyna-startowa.blade.php`, `/szukaj`) i w głównej kolumnie
    `/odkryj` karta jest na miejscu: tablica sąsiaduje tam z „Moim zeszytem"
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
@endphp

{{-- Warstwa powierzchni i `mb-6` TYLKO tam, gdzie tablica stoi obok innych
     powierzchni. W pasie na stronie powitalnej tło i wcięcie daje sam pas —
     patrz `wKarcie` wyżej.

     `sekcja-strony`, nie `card`: tablica dnia jest blokiem strony, a nie
     rzeczą, po którą człowiek tu przyszedł (docs/design/ROLE_KART.md, rola 3).
     Karty treści są dopiero w środku — bez tego rozdzielenia mielibyśmy
     kartę w karcie o tym samym wyglądzie. --}}
<section @class(['sekcja-strony' => $wKarcie, 'kuking-board', 'mb-6' => $wKarcie]) aria-labelledby="kuking-na-dzis">
    <h2 @class(['mt-0', 'text-title-lg' => ! $wKarcie]) id="kuking-na-dzis">
        @if($graSlowem)
            <x-kuking-word forma="i" /> na dziś
        @else
            Co się dziś gotuje
        @endif
    </h2>

    @if($pusta)
        <p class="meta mb-0">
            Dziś jeszcze nikogo nie wybraliśmy.
            Zajrzyj do <a href="{{ route('discover') }}">Świeżo z Kuking</a>.
        </p>
    @else
        <p class="meta">Kilka osób i kilka dań, które dziś warto zobaczyć.</p>

        {{-- OSOBY OBOK DAŃ TAM, GDZIE SIĘ MIESZCZĄ (issue #365).

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
        @if($people->isNotEmpty())
            <div class="kuking-board-kolumna">
                <h3 class="kuking-board-subtitle">Osoby</h3>
                <ul class="kuking-board-people">
                    @foreach($people as $person)
                        <li class="kuking-board-person">
                            <a class="kuking-board-avatar" href="{{ route('profile.show', $person->profile->username) }}" tabindex="-1" aria-hidden="true">
                                <x-avatar :user="$person" :size="120" />
                            </a>

                            <div class="kuking-board-person-body">
                                <a class="author-name" href="{{ route('profile.show', $person->profile->username) }}">{{ $person->displayName() }}</a>
                                <p class="meta m-0">
                                    {{ $person->profile->speciality ?? 'Gotuje w Kuking' }}
                                    @if($person->profile->region) · {{ $person->profile->region }} @endif
                                </p>

                                @if(isset($notes[$person->getKey()]))
                                    <p class="kuking-board-note">{{ $notes[$person->getKey()] }}</p>
                                @endif
                            </div>

                            {{-- Podgląd trzech ostatnich zdjęć. To jest jedyny
                                 uczciwy argument, żeby kogoś zaobserwować.

                                 PASEK STOI JAKO BEZPOŚREDNIE DZIECKO `<li>`
                                 (issue #272), bo `flex-basis: 100%`
                                 z `.kuking-board-preview` opisuje ten pasek jako
                                 ELEMENT rzędu `.kuking-board-person`. Wewnątrz bloku
                                 tekstu (`.kuking-board-person-body`) ta reguła była
                                 martwa: rodzicem był tam zwykły blok, nie kontener
                                 `flex`, więc pasek dostawał 105 px resztki po
                                 awatarze i przycisku, a rząd trzech miniatur
                                 (232 px) zawijał po jednej na wiersz. To jest
                                 dokładnie stan ze zrzutu właściciela.

                                 KOLEJNOŚĆ: ZDJĘCIA, POTEM AKCJA. Do 11 września
                                 pasek stał ZA przyciskiem, bo przycisk siedział
                                 w tym samym wierszu co opis i wstawienie przed nim
                                 elementu na całą szerokość zepchnęłoby go pod
                                 zdjęcia. Dziś na własnym wierszu stoją OBA, więc
                                 o kolejności decyduje już tylko sens: najpierw
                                 dowód (co ta osoba ugotowała), potem decyzja
                                 (obserwuję albo nie). Miniatury są ozdobne
                                 (`alt=""`), nie da się na nie wejść klawiszem
                                 i nie wchodzą do kolejności czytania.

                                 `tests/Feature/SzynaTablicaDniaUkladTest.php` pilnuje
                                 miejsca paska w drzewie — reguła w arkuszu bez tego
                                 miejsca w HTML-u nie robi nic. --}}
                            @php
                                $podglad = $person->posts
                                    ->flatMap(fn ($post) => $post->media)
                                    ->filter(fn ($media) => $media->isReady())
                                    ->take(3);
                            @endphp
                            @if($podglad->isNotEmpty())
                                <div class="kuking-board-preview">
                                    @foreach($podglad as $media)
                                        <img src="{{ $media->url('thumb') }}" alt=""
                                             width="72" height="72" loading="lazy" decoding="async">
                                    @endforeach
                                </div>
                            @endif

                            <div class="kuking-board-akcja">
                                @auth
                                    <form method="POST" action="{{ route('social.follow', $person->profile->username) }}">
                                        @csrf
                                        <button class="btn btn-secondary" type="submit">Obserwuj</button>
                                    </form>
                                @else
                                    {{-- ETYKIETA MÓWI, CO SIĘ STANIE PO KLIKNIĘCIU.

                                         Gość widział tu „Obserwuj" i trafiał na
                                         rejestrację — przycisk obiecywał akcję, której
                                         nie wykonywał. Tekst jest teraz ten sam co na
                                         profilu (`pages/profile/show.blade.php`), żeby
                                         to samo wyjście z serwisu nazywało się wszędzie
                                         tak samo. --}}
                                    <a class="btn btn-secondary" href="{{ route('register') }}">Załóż konto, żeby obserwować</a>
                                @endauth
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($posts->isNotEmpty())
            <div class="kuking-board-kolumna">
                <h3 class="kuking-board-subtitle">Dania</h3>
                <ul class="kuking-board-posts">
                    @foreach($posts as $post)
                        <li class="kuking-board-post">
                            @php $glowne = $post->media->first(fn ($media) => $media->isReady()); @endphp

                            {{-- CAŁY WIERSZ JEST JEDNYM ODNOŚNIKIEM, BEZ PRZYCISKU
                                 „ZOBACZ" NA KOŃCU (zgłoszenie właściciela).

                                 Do 11 września wiersz miał trzy cele kliknięcia
                                 prowadzące w dwa miejsca: zdjęcie (do wpisu), imię
                                 autora (do profilu) i przycisk „Zobacz" (do wpisu,
                                 ten sam adres co zdjęcie). Przycisk powtarzał się
                                 przy KAŻDYM daniu, choć nie robił nic ponad to, co
                                 zdjęcie obok — a stał raz z wcięciem, raz bez,
                                 w zależności od tego, czy danie miało zdjęcie.

                                 Teraz cel jest jeden i obejmuje cały wiersz: zdjęcie,
                                 imię i opis. Cel dotykowy rośnie ze 120 px przycisku
                                 do całej karty, czyli daleko ponad 48 px
                                 z `docs/UX_50_PLUS.md`. Nazwa dostępna odnośnika to
                                 widoczny tekst wiersza („Basia z Podkarpacia, Pierogi
                                 z niedzieli…"), więc mówi, dokąd prowadzi — inaczej
                                 niż dwadzieścia odnośników „Zobacz" na jednej liście.

                                 CO ZA TO ZNIKŁO: przejście do profilu autora wprost
                                 z karty dania (odnośnik w odnośniku nie istnieje
                                 w HTML-u). Nazwisko autora zostaje widoczne, a droga
                                 do profilu jest o jedno kliknięcie dalej — z otwartego
                                 wpisu — i na miejscu w liście „Osoby" wyżej. --}}
                            <a class="kuking-board-post-link" href="{{ $post->url() }}">
                                {{-- ZDJĘCIA NIE MA — NIE MA TEŻ PUSTEGO MIEJSCA PO NIM
                                     (issue #272). Element o zerowej szerokości zabiera
                                     w kontenerze `flex` swój `gap` także wtedy, gdy nic
                                     nie zawiera. Zmierzone przy bazie 32 px: podpis
                                     takiego dania stał 24 px w prawo od krawędzi
                                     wszystkich pozostałych kart w tablicy. --}}
                                @if($glowne)
                                    <span class="kuking-board-post-photo">
                                        <img src="{{ $glowne->url('thumb') }}" alt=""
                                             width="120" height="120" loading="lazy" decoding="async">
                                    </span>
                                @endif

                                {{-- `kuking-board-post-body` — to na tej klasie wisi próg
                                     dwóch kolumn (patrz `app.css`). Bez niej blok bierze
                                     rozmiar bazowy z treści i spada pod zdjęcie nawet
                                     w szynie, w której miejsce jest. --}}
                                <span class="kuking-board-post-body">
                                    <span class="author-name">{{ $post->author->displayName() }}</span>

                                    @if($post->body)
                                        <span class="kuking-board-excerpt">{{ \Illuminate\Support\Str::limit($post->body, 90) }}</span>
                                    @endif

                                    @if(isset($notes[$post->getKey()]))
                                        <span class="kuking-board-note">{{ $notes[$post->getKey()] }}</span>
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
        </div>

        <p class="meta kuking-board-footer">Tu nie ma rankingu. Pokazujemy różne osoby, nie najlepsze.</p>
    @endif
</section>
