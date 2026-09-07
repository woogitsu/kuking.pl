@php
    $isPublic = $recipe->visibility === 'public' && $recipe->isPublished();
    $total = $recipe->totalMinutes();
    // Jedna odpowiedź na „ile porcji" dla znaczka i dla structured data
    // (audyt A28) — dwa osobne teksty to dwie okazje do rozjazdu.
    $porcje = $recipe->servingsLabel();
@endphp
<x-layout
    :title="$recipe->title"
    :description="\Illuminate\Support\Str::limit($recipe->summary ?? $recipe->title, 155)"
    :noindex="! $isPublic"
    {{-- Karta do wysłania rodzinie (issue #14). Zdjęcie podajemy TYLKO dla
         przepisu publicznego: przy szkicu i przepisie dla znajomych nie ma
         czego udostępniać, a adres zdjęcia nie ma po co trafiać do znacznika,
         który zbierają scrapery. --}}
    :image="$isPublic ? $recipe->heroMedia : null"
    ogType="article">

    <x-slot:head>
        @if($isPublic)
            {{--
                Structured data. Świadomie BEZ aggregateRating: nie mamy skali
                ocen, mamy realne wykonania. Podajemy je jako
                interactionStatistic — uczciwie i zgodnie ze znaczeniem
                (docs/seo/SEO_TECHNICAL.md).
            --}}
            @php
                $recipeJsonLd = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Recipe',
                'name' => $recipe->title,
                'description' => $recipe->summary,
                'datePublished' => $recipe->published_at?->toDateString(),
                'author' => [
                    '@type' => 'Person',
                    'name' => $recipe->source_person ?: $recipe->author->displayName(),
                    'url' => route('profile.show', $recipe->author->profile->username),
                ],
                'image' => $recipe->heroMedia?->isReady() ? [$recipe->heroMedia->url('large')] : null,
                'recipeYield' => $porcje,
                'prepTime' => $recipe->prep_minutes ? 'PT'.$recipe->prep_minutes.'M' : null,
                'cookTime' => $recipe->cook_minutes ? 'PT'.$recipe->cook_minutes.'M' : null,
                'totalTime' => $recipe->totalTimeIso(),
                'recipeIngredient' => $recipe->ingredients->pluck('ingredient_text')->all(),
                'recipeInstructions' => $recipe->steps->map(fn ($step) => [
                    '@type' => 'HowToStep',
                    'position' => $step->position + 1,
                    'text' => $step->instruction,
                ])->all(),
                'interactionStatistic' => $cookedCount > 0 ? [
                    '@type' => 'InteractionCounter',
                    'interactionType' => 'https://schema.org/CookAction',
                    'userInteractionCount' => $cookedCount,
                ] : null,
                'inLanguage' => 'pl-PL',
            ], static fn ($value) => $value !== null && $value !== []);
            @endphp
            <x-json-ld :data="$recipeJsonLd" />

            @php
                $breadcrumbJsonLd = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Kuking', 'item' => route('landing')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Przepisy', 'item' => route('discover')],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $recipe->title],
                ],
            ];
            @endphp
            <x-json-ld :data="$breadcrumbJsonLd" />
        @endif
    </x-slot:head>

    <article class="stack">
        <header>
            {{--
                OKRUSZKI (kit v2, ekrany 02 i 06).

                Na stronę przepisu wchodzi się najczęściej prosto z Google —
                bez ekranu startowego po drodze i bez pojęcia, gdzie się jest.
                Dane strukturalne wyżej mówią to samo Google'owi od dawna;
                to jest ta sama informacja pokazana człowiekowi.
            --}}
            <ol class="okruchy">
                <li><a href="{{ auth()->check() ? route('home') : route('landing') }}">Start</a></li>
                <li><a href="{{ route('discover') }}">Przepisy</a></li>
            </ol>

            <p class="meta mb-2">{{ $recipe->attributionLine() }}</p>
            <h1 class="mt-0">{{ $recipe->title }}</h1>

            @if($recipe->status === \App\Models\Recipe::STATUS_HIDDEN)
                {{--
                    Ukrycie przez moderację nie jest szkicem (audyt A08).
                    Wcześniej autor dostawał tu komunikat „To jest szkic.
                    Kliknij Edytuj”, a „Edytuj” oddawało 403 — interfejs
                    obiecywał akcję, której nie ma, i nie mówił, co zrobić.
                --}}
                <p class="notice kolumna-czytania">
                    <strong>Ten przepis jest ukryty przez moderację.</strong>
                    Nie widzą go inne osoby i na razie nie da się go zmieniać.
                    Jeśli uważasz, że to pomyłka, napisz do nas:
                    {{ config('kuking.community.contact_email') }}
                </p>
            @elseif(! $recipe->isPublished())
                <p class="notice kolumna-czytania"><strong>To jest szkic.</strong> Widzisz go tylko Ty. Kliknij „Edytuj”, żeby dokończyć i opublikować.</p>
            @endif

            <div class="przepis-autor mb-4">
                <x-avatar :user="$recipe->author" :size="44" />
                <div class="min-w-0">
                    <a class="author-name" href="{{ route('profile.show', $recipe->author->profile->username) }}">{{ $recipe->author->displayName() }}</a>
                    <x-konto-przykladowe :user="$recipe->author" />
                    <p class="meta m-0">
                        @if($recipe->published_at)
                            <time datetime="{{ $recipe->published_at->toIso8601String() }}">{{ \App\Support\Czas::data($recipe->published_at, 'j F Y') }}</time>
                        @endif
                    </p>
                </div>

                {{--
                    „OBSERWUJ" PRZY AUTORZE (UI kit v2, ekran 02).

                    To nie jest ozdoba przeniesiona z makiety. Strona przepisu
                    jest najczęstszym wejściem z Google, a obserwowanie autora
                    to jedyny powód, dla którego ktoś tu wróci. Do tej pory,
                    żeby zacząć obserwować, trzeba było najpierw wejść na profil
                    — czyli opuścić przepis, po który się przyszło.
                --}}
                @auth
                    @if(auth()->id() !== $recipe->author_id)
                        {{--
                            Przycisk „Obserwuj" przez POLICY, nie przez samo
                            „nie jestem autorem" (D-022). Przepis konta
                            wymazanego (`erased`) zostaje widoczny, a polityka
                            obserwowania wymaga konta AKTYWNEGO — bez tego
                            warunku stałby tu przycisk, który zawsze kończy się
                            403. Przycisk zapraszający w ścianę to ta sama
                            klasa błędu co karta osoby z linkiem do 403
                            (audyt W5-08). „Przestań obserwować" zostaje bez
                            warunku: kto zaczął obserwować przed wymazaniem
                            konta, musi mieć jak przestać.
                        --}}
                        @if($obserwuje ?? false)
                            <form method="POST" action="{{ route('social.unfollow', $recipe->author->profile->username) }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-secondary" type="submit">Przestań obserwować</button>
                            </form>
                        @else
                            @can('follow', $recipe->author)
                                <form method="POST" action="{{ route('social.follow', $recipe->author->profile->username) }}">
                                    @csrf
                                    <button class="btn btn-secondary" type="submit">Obserwuj</button>
                                </form>
                            @endcan
                        @endif
                    @endif
                @endauth
            </div>
        </header>

        {{--
            HERO WEDŁUG KITU (ekran 02): zdjęcie po lewej, panel po prawej.

            Kolejność w kodzie jest kolejnością na TELEFONIE i jest to
            kolejność z ekranu 06: zdjęcie, liczby, akcje, „Skąd ten przepis".
            Desktop tylko przesuwa panel obok zdjęcia — nie przestawia go
            w innym miejscu drzewa, więc czytnik ekranu i klawiatura chodzą
            w obu układach tak samo.

            Bez zdjęcia panel bierze całą szerokość zamiast zostawiać po
            lewej pustą połowę ekranu.
        --}}
        <div class="przepis-hero @if($recipe->heroMedia) przepis-hero-ze-zdjeciem @endif">
            @if($recipe->heroMedia)
                <div class="przepis-hero-zdjecie">
                    <x-photo :media="$recipe->heroMedia" variant="large" :priority="true" class="post-photo" />
                </div>
            @endif

            <div class="card przepis-panel">
                {{--
                    KAFLE LICZB: czas, porcje, poziom.

                    Pokazujemy TYLKO to, co autor podał. Kit rysuje zawsze trzy
                    kafle, ale kafel „—" nie jest informacją: mówi „nie wiemy",
                    zajmując tyle miejsca, co odpowiedź.
                --}}
                @if($total || $porcje || $recipe->difficultyLabel())
                    <ul class="przepis-liczby">
                        @if($total)
                            <li class="przepis-liczba">
                                <x-ikona nazwa="clock" :rozmiar="26" />
                                <div><strong>Około {{ $total }} min</strong><span>Czas</span></div>
                            </li>
                        @endif
                        @if($porcje)
                            <li class="przepis-liczba">
                                <x-ikona nazwa="users" :rozmiar="26" />
                                <div><strong>{{ $porcje }}</strong><span>Ilość</span></div>
                            </li>
                        @endif
                        @if($recipe->difficultyLabel())
                            <li class="przepis-liczba">
                                <x-ikona nazwa="chef" :rozmiar="26" />
                                <div><strong>{{ $recipe->difficultyLabel() }}</strong><span>Poziom</span></div>
                            </li>
                        @endif
                    </ul>
                @endif

                {{--
                    GŁÓWNA AKCJA PRZEPISU. Nie „Lubię to", a „Ugotowałem".

                    Do etapu C stała na samym dole strony, pod składnikami
                    i krokami — czyli tam, gdzie trafiał tylko ten, kto
                    przewinął cały przepis. Kit stawia ją w panelu obok
                    zdjęcia i to jest właściwe miejsce: widać ją od razu,
                    a wraca się do niej po ugotowaniu bez szukania.
                --}}
                <div class="przepis-akcje">
                    @auth
                        {{--
                            BEZ ZNAKU „UŚMIECH" W TYM PRZYCISKU, wbrew kitowi.

                            Kit wkleja go tutaj, ale znak rysuje garnek kolorem
                            bieżącym, a uśmiech kolorem powierzchni. Na tle
                            marki daje to biały garnek z uśmiechem w kolorze
                            białego tła — czyli plamę bez uśmiechu. Znak,
                            którego nie widać, jest gorszy niż jego brak.
                        --}}
                        <a class="btn btn-primary" href="{{ route('cooked.create', $recipe->slug) }}">Ugotowałem</a>
                        @if($isSaved)
                            <form method="POST" action="{{ route('collections.unsave', $recipe->slug) }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-secondary" type="submit"><x-ikona nazwa="save" /> Usuń z zeszytu</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('collections.save', $recipe->slug) }}">
                                @csrf
                                <button class="btn btn-secondary" type="submit"><x-ikona nazwa="save" /> Zapisuję</button>
                            </form>
                        @endif
                    @else
                        <a class="btn btn-primary" href="{{ route('register') }}">Załóż konto, żeby dać znać autorowi</a>
                    @endauth

                    {{--
                        „Gotuję" — tryb pełnoekranowy (issue #24). Widoczny
                        tylko, gdy przepis w ogóle ma kroki: bez nich nie
                        miałby czego pokazać, a kontroler i tak zawraca
                        z czytelnym komunikatem, gdyby ktoś trafił tu wprost.
                    --}}
                    @if($recipe->steps->isNotEmpty())
                        <a class="btn btn-secondary" href="{{ route('cooking.show', $recipe->slug) }}">Gotuję — pokaż kroki na cały ekran</a>
                    @endif
                </div>

                {{-- „Skąd ten przepis” stoi PRZED składnikami. To jest decyzja
                     produktowa, nie kolejność przypadkowa. --}}
                @if($recipe->source_note || $recipe->source_person)
                    <section class="recipe-story">
                        <h2 class="mt-0 text-title-sm">Skąd ten przepis</h2>
                        @if($recipe->source_person)
                            <p><strong>Po {{ $recipe->source_person }}.</strong></p>
                        @endif
                        @if($recipe->source_note)
                            <p class="whitespace-pre-line mb-0">{{ $recipe->source_note }}</p>
                        @endif
                        @if($recipe->sourceScan)
                            <div class="mt-4">
                                <x-photo :media="$recipe->sourceScan" variant="feed" class="post-photo" />
                                <p class="meta">Kartka, z której jest ten przepis.</p>
                            </div>
                        @endif
                    </section>
                @endif

                @if($recipe->source_type === 'external' && $recipe->source_url)
                    <p class="meta m-0">Przepis pochodzi ze strony: <a href="{{ $recipe->source_url }}" rel="nofollow noopener">{{ $recipe->source_url }}</a></p>
                @endif
            </div>
        </div>

        {{--
            Plakietki, których kit nie ma, a które są tym, czym Kuking różni
            się od bazy receptur: ile osób to naprawdę zrobiło i od kiedy
            przepis jest w rodzinie. Zostają POD hero, żeby nie konkurowały
            z trzema liczbami, które mówią „czy zdążę i dla ilu osób".
        --}}
        <ul class="recipe-facts">
            @if($cookedCount > 0)<li><span class="badge badge-cooked">Ugotowane {{ $cookedCount }} ×</span></li>@endif
            {{-- „X z Y osób zrobi to ponownie" — od trzech ocen (SOUL 4.2).
                 Poniżej trzech jedna opinia waży za dużo, a zdanie brzmi jak
                 werdykt, którym nie jest. --}}
            @if($oceniloWykonanie >= 3)
                <li><span class="badge badge-cooked">{{ $zrobiaPonownie }} z {{ $oceniloWykonanie }} {{ \App\Support\Odmiana::rzeczownik($oceniloWykonanie, 'osoby', 'osób', 'osób') }} zrobi to ponownie</span></li>
            @endif
            @if($recipe->family_since_year)<li><span class="badge badge-cooked">W rodzinie od {{ $recipe->family_since_year }}</span></li>@endif
        </ul>

        @if($recipe->summary)
            <p class="text-lead kolumna-czytania">{{ $recipe->summary }}</p>
        @endif

        {{--
            SKŁADNIKI OBOK KROKÓW (kit, ekran 02).

            Poniżej 60rem jedno pod drugim, składniki pierwsze: przy gotowaniu
            najpierw sprawdza się, czy ma się z czego, a dopiero potem co po
            kolei.

            D-017: składnik zostaje JEDNYM polem wolnego tekstu, bez kolumny
            ilości z kitu. Rozbijanie „500 g mąki pszennej" na dwie kolumny
            wymagałoby zgadywania, gdzie kończy się ilość — a zgadywanie na
            ekranie przepisu to zła ilość mąki.
        --}}
        <div class="przepis-siatka">
            <section class="card">
                <h2>Składniki</h2>
                @if($recipe->ingredients->isEmpty())
                    <p class="meta">Autor jeszcze nie dodał składników.</p>
                @else
                    <ul class="ingredient-list">
                        @foreach($recipe->ingredients as $ingredient)
                            <li>
                                {{ $ingredient->ingredient_text }}
                                {{-- „do smaku” tylko wtedy, gdy autor NIE napisał
                                     tego sam w tekście składnika (issue #44).
                                     „Sól do smaku — do smaku” wygląda jak usterka,
                                     a nie jak informacja. --}}
                                @if($ingredient->no_amount && ! str_contains(mb_strtolower($ingredient->ingredient_text), 'do smaku'))
                                    <span class="meta"> — do smaku</span>
                                @endif
                                @if($ingredient->note)<span class="meta"> — {{ $ingredient->note }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="card">
                <h2>Przygotowanie</h2>
                @if($recipe->steps->isEmpty())
                    <p class="meta">Autor jeszcze nie opisał przygotowania.</p>
                @else
                    {{-- D-017: numer i akapit, BEZ tytułu kroku z kitu.
                         Autor pisze jeden ciąg zdań i nie ma skąd wziąć
                         tytułu, którego nie napisał. --}}
                    <ol class="step-list">
                        @foreach($recipe->steps as $step)
                            <li>
                                <span class="step-number" aria-hidden="true">{{ $step->position + 1 }}</span>
                                <div>
                                    <span class="visually-hidden">Krok {{ $step->position + 1 }}.</span>
                                    <p class="m-0 whitespace-pre-line">{{ $step->instruction }}</p>
                                    @if($step->media)
                                        <div class="mt-3 max-w-[20rem]">
                                            <x-photo :media="$step->media" variant="feed" class="post-photo" />
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>

        @auth
            <p>
                {{-- Przycisk pyta Policy, a nie tylko o autorstwo: dla przepisu
                     ukrytego przez moderację edycja jest zamknięta (audyt A08),
                     więc nie pokazujemy guzika prowadzącego do 403. --}}
                @if(auth()->id() === $recipe->author_id)
                    @can('update', $recipe)
                        <a class="btn btn-secondary" href="{{ route('recipes.edit', $recipe->slug) }}">Edytuj przepis</a>
                    @endcan
                @else
                    <a class="btn btn-quiet" href="{{ route('reports.create', ['type' => 'recipe', 'id' => $recipe->slug]) }}">Zgłoś</a>
                @endif
            </p>
        @endauth

        {{--
            KOMU WYSZŁO — UI kit v2, ekrany 02/06, domknięcie etapu C.

            Nagłówek zostaje „Komu wyszło" (COPY_STYLE.md §6), nie „Jak wyszło
            innym?" z kitu — wdrażamy UKŁAD kitu, nie jego tekst
            (docs/design/STAN_WDROZENIA_KITU.md, konflikt rozstrzygnięty
            7 IX 2026 na rzecz COPY_STYLE).

            PASEK LICZB jest tym, czym w kicie („18 osób ugotowało · 92%
            zrobi ponownie"): te same wartości, które kontroler liczy dla
            znaczków nad zdjęciem (`cookedCount`, `zrobiaPonownie`,
            `oceniloWykonanie`) — żadnego nowego zapytania, żadnego
            szacowania. Ten sam próg trzech ocen co przy znaczku wyżej.

            BEZ „jednego reprezentatywnego wiersza ze zdjęciem-miniaturą"
            z kitu — świadomie. Zdjęcie cudzego wykonania jest tu
            najważniejszym elementem (UX_50_PLUS.md) i nie wolno go zmniejszać
            do ikonki w imię układu, więc karty zostają pełnowymiarowe
            (`x-cooked-card`), a „układ z kitu" przechodzi na to, co
            NIEZALEŻNE od rozmiaru zdjęcia: pasek liczb nad listą i przycisk
            prowadzący do reszty.

            PRZYCISK „Zobacz N wpisów" z kitu → `x-show-more` z „wykonań"
            (ten sam komponent i to samo słowo, którego już używa zakładka
            „Ugotowane" na profilu) zamiast nowego, wymyślonego tekstu —
            COPY_STYLE nie przewiduje osobnego brzmienia dla tego przycisku,
            a spójny czasownik w całym serwisie jest ważniejszy niż literalna
            zgodność z makietą. Prowadzi naprawdę do reszty wykonań: strona
            jest paginowana (`RecipeController::show()`, parametr `wykonania`,
            osobny od `komentarze` obok), więc kliknięcie pokazuje kolejne
            PRAWDZIWE wykonania, nie placeholder.
        --}}
        <section class="stack" aria-labelledby="komu-wyszlo">
            <div class="komu-wyszlo-naglowek">
                <h2 id="komu-wyszlo" class="m-0">Komu wyszło</h2>
                @if($cookedCount > 0)
                    <p class="pasek-liczb meta m-0">
                        {{ $cookedCount }} {{ \App\Support\Odmiana::rzeczownik($cookedCount, 'osoba ugotowała', 'osoby ugotowały', 'osób ugotowało') }} to danie
                        @if($oceniloWykonanie >= 3)
                            · {{ (int) round($zrobiaPonownie / $oceniloWykonanie * 100) }}% zrobi to ponownie
                        @endif
                    </p>
                @endif
            </div>

            @if($cookedEvents->isNotEmpty())
                <p class="meta">Zdjęcia od ludzi, którzy naprawdę to zrobili u siebie.</p>
                @foreach($cookedEvents as $event)
                    <x-cooked-card :event="$event" />
                @endforeach
                <x-show-more :paginator="$cookedEvents" czego="wykonań" />
            @else
                {{-- C3: przepis z zerem wykonań wyglądał jak odrzucony — sekcja
                     po prostu znikała ze strony. SOUL 4.2 wymienia to jako
                     ryzyko wprost i podaje ten tekst. --}}
                <x-empty-state title="Jeszcze nikt tego nie gotował">
                    <p class="mb-0">Będziesz pierwsza albo pierwszy?</p>
                </x-empty-state>
            @endif
        </section>

        @if(auth()->id() === $recipe->author_id)
            <div class="danger-zone kolumna-czytania">
                <x-confirm-button
                    :action="route('recipes.destroy', $recipe->slug)"
                    label="Usuń ten przepis"
                    question="Na pewno usunąć ten przepis? Wykonania i komentarze innych osób też przestaną być widoczne." />
            </div>
        @endif

        <div class="kolumna-czytania">
            <x-comment-thread :comments="$komentarze" :ile="$komentarzyRazem" :action="route('recipes.comment', $recipe->slug)" />
        </div>
    </article>
</x-layout>
