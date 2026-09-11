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

    `graSlowem` — DAWKOWANIE, NIE OZDOBA (issue #38)
    `docs/brand/COPY_STYLE.md` §2 dopuszcza grę słowem „kuKING" NAJWYŻEJ RAZ
    NA EKRAN. Na stronie powitalnej pierwsze miejsce jest już zajęte przez
    przycisk „Zostań kuKINGiem", który §8 przypisuje tam wprost — więc tablica
    dostaje na tym jednym ekranie nagłówek zapasowy „Co się dziś gotuje".
    To nie jest nowy tekst: §5 podaje go jako gotową alternatywę („nie odmienia
    słowa wcale, problem znika u źródła", D-013). Wszędzie indziej — /home,
    /odkryj, /szukaj — tablica jest jedynym takim miejscem na ekranie
    i zostaje przy nazwie „kuKINGi na dziś".
--}}
@props(['people', 'posts', 'notes' => [], 'graSlowem' => true])

@php $pusta = $people->isEmpty() && $posts->isEmpty(); @endphp

<section class="sekcja-strony kuking-board mb-6" aria-labelledby="kuking-na-dzis">
    <h2 class="mt-0" id="kuking-na-dzis">
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

        @if($people->isNotEmpty())
            <h3 class="kuking-board-subtitle">Osoby</h3>
            <ul class="kuking-board-people">
                @foreach($people as $person)
                    <li class="kuking-board-person">
                        <a href="{{ route('profile.show', $person->profile->username) }}" tabindex="-1" aria-hidden="true">
                            <x-avatar :user="$person" :size="52" />
                        </a>

                        <div class="min-w-0 flex-1">
                            <a class="author-name" href="{{ route('profile.show', $person->profile->username) }}">{{ $person->displayName() }}</a>
                            <p class="meta m-0">
                                {{ $person->profile->speciality ?? 'Gotuje w Kuking' }}
                                @if($person->profile->region) · {{ $person->profile->region }} @endif
                            </p>

                            @if(isset($notes[$person->getKey()]))
                                <p class="kuking-board-note">{{ $notes[$person->getKey()] }}</p>
                            @endif
                        </div>

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

                        {{-- Podgląd trzech ostatnich zdjęć. To jest jedyny
                             uczciwy argument, żeby kogoś zaobserwować.

                             PASEK STOI JAKO BEZPOŚREDNIE DZIECKO `<li>`, ZA
                             PRZYCISKIEM — i jedno, i drugie jest tu konieczne
                             (issue #272).

                             Bezpośrednie dziecko, bo `flex-basis: 100%`
                             z `.kuking-board-preview` opisuje ten pasek jako
                             ELEMENT rzędu `.kuking-board-person`. Wewnątrz bloku
                             tekstu (`.min-w-0.flex-1`) ta reguła była martwa:
                             rodzicem był tam zwykły blok, nie kontener `flex`,
                             więc pasek dostawał 105 px resztki po awatarze
                             i przycisku, a rząd trzech miniatur (232 px) zawijał
                             po jednej na wiersz. To jest dokładnie stan ze zrzutu
                             właściciela.

                             Za przyciskiem, bo `flex-basis: 100%` zawsze zaczyna
                             nowy wiersz. Postawiony PRZED formularzem zepchnąłby
                             „Obserwuj" do trzeciego wiersza, czyli pod zdjęcia.
                             Kolejność czytania na tym nie traci: miniatury są
                             ozdobne (`alt=""`) i nie da się na nie wejść klawiszem,
                             a nazwa osoby i jej opis stoją nadal PRZED przyciskiem.

                             `tests/Feature/SzynaTablicaDniaUkladTest.php` pilnuje
                             obu tych rzeczy — reguła w arkuszu bez tego miejsca
                             w HTML-u nie robi nic. --}}
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
                    </li>
                @endforeach
            </ul>
        @endif

        @if($posts->isNotEmpty())
            <h3 class="kuking-board-subtitle">Dania</h3>
            <ul class="kuking-board-posts">
                @foreach($posts as $post)
                    <li class="kuking-board-post">
                        @php $glowne = $post->media->first(fn ($media) => $media->isReady()); @endphp

                        {{-- CAŁY ODNOŚNIK ZE ZDJĘCIEM ZNIKA, GDY ZDJĘCIA NIE MA
                             (issue #272). Wcześniej `@if` stał w środku, więc wpis
                             bez gotowego zdjęcia zostawiał w rzędzie PUSTY element
                             o zerowej szerokości — a element w kontenerze `flex`
                             zabiera swój `gap` także wtedy, gdy nic nie zawiera.
                             Zmierzone przy bazie 32 px: podpis takiego dania stał
                             24 px w prawo od krawędzi wszystkich pozostałych kart
                             w tablicy. Przy okazji ubywa z drzewa dostępności
                             odnośnik, który nie prowadził do niczego widocznego. --}}
                        @if($glowne)
                            <a href="{{ $post->url() }}" class="kuking-board-post-photo" tabindex="-1" aria-hidden="true">
                                <img src="{{ $glowne->url('thumb') }}" alt=""
                                     width="96" height="96" loading="lazy" decoding="async">
                            </a>
                        @endif

                        {{-- `kuking-board-post-body` — to na tej klasie wisi próg
                             dwóch kolumn (patrz `app.css`). Bez niej blok bierze
                             rozmiar bazowy z treści i spada pod zdjęcie nawet
                             w szynie, w której miejsce jest. --}}
                        <div class="min-w-0 kuking-board-post-body">
                            <p class="m-0 mb-1">
                                <a class="author-name" href="{{ route('profile.show', $post->author->profile->username) }}">{{ $post->author->displayName() }}</a>
                            </p>

                            @if($post->body)
                                <p class="kuking-board-excerpt">{{ \Illuminate\Support\Str::limit($post->body, 90) }}</p>
                            @endif

                            @if(isset($notes[$post->getKey()]))
                                <p class="kuking-board-note">{{ $notes[$post->getKey()] }}</p>
                            @endif

                            <a class="btn btn-quiet btn-quiet-bez-wciecia" href="{{ $post->url() }}">Zobacz</a>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="meta kuking-board-footer">Tu nie ma rankingu. Pokazujemy różne osoby, nie najlepsze.</p>
    @endif
</section>
