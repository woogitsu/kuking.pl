@php
    $isPublic = $recipe->visibility === 'public' && $recipe->isPublished();
    $total = $recipe->totalMinutes();
    // Jedna odpowiedź na „ile porcji" dla znaczka i dla structured data
    // (audyt A28) — dwa osobne teksty to dwie okazje do rozjazdu.
    $porcje = $recipe->servingsLabel();
    // Dokąd iść po wymianę odrzuconego zdjęcia (#752). Pyta Policy, tak jak
    // przycisk edycji niżej — przepis ukryty przez moderację edycji nie ma.
    $edycjaZdjecPrzepisu = auth()->user()?->can('update', $recipe)
        ? route('recipes.edit', $recipe->slug)
        : null;
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
    {{-- Treść wykorzystuje szerokość ramy: tekst i zdjęcie w hero, akcje poniżej. --}}
    :szynaWTresci="true"
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
                /*
                 * AUTOR TO KONTO, KTÓRE PRZEPIS OPUBLIKOWAŁO — I TYLKO ONO.
                 *
                 * Do 11 września 2026 `name` brało się z `source_person`,
                 * a `url` prowadził do profilu konta. Jeden obiekt `Person`
                 * dostawał więc imię jednej rzeczy i adres innej, a do tego
                 * `@type: Person` deklarował typ encji, którego nikt nie zna:
                 * w `source_person` stoi wolny tekst i bywa tam nazwa grupy
                 * na Facebooku, bywa „od mamy", bywa „Nasze smaki".
                 *
                 * Google traktuje niezgodność danych strukturalnych
                 * z rzeczywistością jako naruszenie wytycznych (`sd-policies`,
                 * docs/seo/SEO_TECHNICAL.md sekcja 2). Mapowanie z sekcji 2.1
                 * tego dokumentu mówi zresztą dokładnie to samo od początku:
                 * `author.name` i `author.url` biorą się z
                 * `profiles.display_name` i `profiles.username` autora
                 * (`recipes.author_id`). Nazwa i adres z jednego konta.
                 */
                'author' => [
                    '@type' => 'Person',
                    'name' => $recipe->author->displayName(),
                    'url' => route('profile.show', $recipe->author->profile->username),
                ],
                /*
                 * POCHODZENIE PRZEPISU IDZIE DO `citation`, NIE DO `author`.
                 *
                 * `citation` przyjmuje `Text` obok `CreativeWork` (schema.org
                 * V30.0, sprawdzone na https://schema.org/citation) i stoi na
                 * `CreativeWork`, po którym `Recipe` dziedziczy
                 * (Thing > CreativeWork > HowTo > Recipe). Jako zwykły napis
                 * NIE KAŻE NAM DEKLAROWAĆ TYPU ENCJI — a to jest dokładnie
                 * ten błąd, który tu naprawiamy.
                 *
                 * Odrzucone świadomie:
                 * - `sourceOrganization` — przyjmuje wyłącznie `Organization`,
                 *   czyli ten sam fałsz co `Person`, tylko z drugiej strony;
                 * - `isBasedOn` — przyjmuje `CreativeWork`, `Product` albo
                 *   `URL`, nie `Text`; „od mamy" nie jest adresem;
                 * - `recipeSource` — nie istnieje w schema.org
                 *   (https://schema.org/recipeSource oddaje 404); to pole ze
                 *   starego mikroformatu hRecipe.
                 *
                 * Wartość idzie DOSŁOWNIE, bez doklejanego przyimka i bez
                 * zmiany wielkości liter — tak samo jak w podpisie nad tytułem
                 * (`Recipe::attributionLine()`), więc dane strukturalne
                 * pokazują to, co widzi człowiek.
                 *
                 * `?:` zamienia pusty napis na `null`, żeby `array_filter` na
                 * końcu bloku wyrzucił to pole tak samo jak każde inne puste —
                 * sam `array_filter` przepuszcza `''`.
                 */
                'citation' => $recipe->source_person ?: null,
                /*
                 * ADRES STRONY, Z KTÓREJ PRZEPIS POCHODZI, IDZIE DO `isBasedOn`.
                 *
                 * To jest drugie pół tej samej sprawy co `citation` wyżej,
                 * tylko z odwrotnym rozstrzygnięciem — i z tego samego powodu.
                 * Zasada z D-156 brzmi: wartości, o której NIE WIEMY, jakim
                 * typem encji jest, nie wolno wkładać do pola, które typ
                 * wymusza. Tutaj typ jest ZNANY: `recipes.source_url` jest
                 * adresem strony i niczym innym — obie drogi zapisu walidują
                 * go regułą `url` (`RecipeController::rules()`,
                 * `components/recipe-wizard.blade.php`), a widok pokazuje tę
                 * wartość człowiekowi jako link (niżej na tej stronie).
                 *
                 * `isBasedOn` przyjmuje `CreativeWork`, `Product` albo `URL`
                 * i stoi na `CreativeWork`, po którym `Recipe` dziedziczy
                 * (Thing > CreativeWork > HowTo > Recipe) — sprawdzone na
                 * https://schema.org/isBasedOn, V30.0. Jako `URL` wartość
                 * idzie zwykłym napisem, więc NIE deklarujemy żadnego
                 * `@type`: goły adres nie udaje ani osoby, ani organizacji.
                 * `isBasedOnUrl` odrzucone: schema.org oznacza je jako
                 * zastąpione przez `isBasedOn` („SupersededBy”).
                 *
                 * BRAMKA `source_type` JEST KONIECZNA, nie ozdobna. Adres
                 * bywa wypełniony także przy przepisie własnym czy rodzinnym
                 * (formularz nie ukrywa tego pola), a wtedy widoczna treść
                 * strony NIE pokazuje go wcale. Dane strukturalne muszą
                 * odzwierciedlać widoczną treść (`sd-policies`,
                 * docs/seo/SEO_TECHNICAL.md sekcja 2), więc warunek jest tu
                 * DOKŁADNIE ten sam co przy widocznym zdaniu „Przepis
                 * pochodzi ze strony".
                 *
                 * `?:` jak przy `citation`: `array_filter` na końcu bloku
                 * odrzuca `null` i `[]`, ale PUSTY NAPIS BY PRZEPUŚCIŁ.
                 */
                'isBasedOn' => $recipe->source_type === \App\Models\Recipe::SOURCE_EXTERNAL
                    ? ($recipe->source_url ?: null)
                    : null,
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
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Świeżo z Kuking', 'item' => route('discover')],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $recipe->title],
                ],
            ];
            @endphp
            <x-json-ld :data="$breadcrumbJsonLd" />
        @endif
    </x-slot:head>

    {{-- Bezpośredni header zachowuje semantykę i pomiar typografii portu. --}}
    <article class="stack przepis-uklad marka-przepis">
        <header class="marka-przepis-hero">
            <div class="marka-przepis-tekst">
            {{--
                OKRUSZKI (kit v2, ekrany 02 i 06).

                Na stronę przepisu wchodzi się najczęściej prosto z Google —
                bez ekranu startowego po drodze i bez pojęcia, gdzie się jest.
                Dane strukturalne wyżej mówią to samo Google'owi od dawna;
                to jest ta sama informacja pokazana człowiekowi.
            --}}
            <ol class="okruchy">
                <li><a href="{{ auth()->check() ? route('home') : route('landing') }}">Start</a></li>
                <li><a href="{{ route('discover') }}">Świeżo z Kuking</a></li>
            </ol>

            {{-- Odstępy w nagłówku przepisu robi CSS (`.przepis-uklad > header`
                 w app.css), a nie klasy `mb-2` / `mt-0` / `mb-4` stojące tu
                 wcześniej. Utility leży w warstwie PO `components`, więc
                 dopóki tu były, żadna reguła arkusza nie mogła ich poprawić
                 — a rytm nagłówka jest własnością strony przepisu, nie
                 trzech osobnych miejsc w szablonie. --}}
            <p class="meta">{{ $recipe->attributionLine() }}</p>
            <h1>{{ $recipe->title }}</h1>

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

            <div class="przepis-autor">
                <x-avatar :user="$recipe->author" :size="44" />
                <div class="min-w-0">
                    <a class="author-name" href="{{ route('profile.show', $recipe->author->profile->username) }}">{{ $recipe->author->displayName() }}</a>
                    <p class="meta m-0">
                        @if($recipe->published_at)
                            <time datetime="{{ $recipe->published_at->toIso8601String() }}">{{ \App\Support\Czas::data($recipe->published_at, 'j F Y') }}</time>
                        @endif
                        {{-- Plakietka cicha „konto przykładowe" (D-032) w wierszu metadanych, po dacie — kropkę
                             rysuje sam komponent. Przepis nieopublikowany
                             widzi jego autor i moderator, a wtedy daty nad
                             plakietką nie ma i nie ma czego oddzielać. --}}
                        <x-konto-przykladowe :user="$recipe->author" :kropka="$recipe->published_at !== null" />
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
                                {{-- #793 rozszerzone na relacje: strona przepisu
                                     bywa otwarta godzinami, a nazwa autora
                                     w adresie mogła w tym czasie zmienić
                                     właściciela. --}}
                                <input type="hidden" name="oczekiwany_id" value="{{ $recipe->author->getKey() }}">
                                <button class="btn btn-secondary" type="submit">Przestań obserwować</button>
                            </form>
                        @else
                            @can('follow', $recipe->author)
                                <form method="POST" action="{{ route('social.follow', $recipe->author->profile->username) }}">
                                    @csrf
                                    <input type="hidden" name="oczekiwany_id" value="{{ $recipe->author->getKey() }}">
                                    <button class="btn btn-secondary" type="submit">Obserwuj</button>
                                </form>
                            @endcan
                        @endif
                    @endif
                @endauth
            </div>
                {{-- Opis i dane autora należą do tekstowej połowy hero. --}}
        @if($recipe->summary)
            <p class="text-lead kolumna-czytania">{{ $recipe->summary }}</p>
        @endif
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


            </div>
            @if($recipe->heroMedia)
                <div class="przepis-hero-zdjecie marka-przepis-zdjecie">
                    <x-photo :media="$recipe->heroMedia" variant="large" :priority="true" class="post-photo"
                             tresc="przepis" :wymien-url="$edycjaZdjecPrzepisu ? $edycjaZdjecPrzepisu.'#f-hero_photo' : null" />
                </div>
            @endif
        </header>
        <div class="card przepis-panel">

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
                        {{--
                            OPERACJA GLOBALNA — PYTA PRZED AKCJĄ I NAZYWA
                            ZAKRES PO NIEJ (issue #775 + D-224/D-231 = D-230).

                            Ten przycisk nie wie, w którym zeszycie stoi
                            człowiek — przepis mógł być zapisany w kilku naraz
                            przez „Wybierz zeszyt" niżej. Zarzut z #775 był
                            podwójny: „Usunięte z zeszytu" po fakcie ani nie
                            mówiło, że zniknęło z KAŻDEGO zeszytu, ani nie
                            pytało przed usunięciem notatek, których żadna
                            droga powrotu nie odtwarza (`detach()` kasuje
                            wiersz pivotu razem z `note`, D-230). D-224
                            rozstrzygnęło, że pytanie przed KAŻDĄ odwracalną
                            czynnością uczy odklikiwania — ale to rozstrzygnięcie
                            liczyło z odwracalnością całej akcji, nie z tym, że
                            część jej skutku (notatki) nie wraca. Stąd pytanie
                            wraca tu, na jedynym ekranie o zasięgu globalnym.

                            Po potwierdzeniu komunikat nazywa zakres LICZBĄ
                            („Przepis wyjęty z 3 Twoich zeszytów") i daje
                            przycisk „Zapisz ponownie"
                            (`CollectionController::komunikatPoWyjeciu()`) —
                            ale mówi też wprost, że wraca sam zapis, nie
                            notatka przy nim.

                            Usunięcie z JEDNEGO, wybranego zeszytu robi się
                            w widoku tego zeszytu, bez pytania — tam przycisk
                            nazywa się „Usuń z tego zeszytu" i notatki innych
                            zeszytów w ogóle nie dotyczy (D-231).
                        --}}
                        <x-confirm-button
                            :action="route('collections.unsave', $recipe->slug)"
                            label="Usuń z zeszytu"
                            question="Usunąć ten przepis ze wszystkich Twoich zeszytów, w których go zapisano? Notatki przy nim znikną razem z zapisem." />
                    @else
                        <form method="POST" action="{{ route('collections.save', $recipe->slug) }}">
                            @csrf
                            <button class="btn btn-secondary" type="submit"><x-ikona nazwa="save" /> Zapisuję</button>
                        </form>
                    @endif
                    <x-wybor-zeszytu :action="route('collections.save', $recipe->slug)" :wiersz="'przepis-'.$recipe->getKey()" :content="$recipe" />
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

            {{-- „Podziel się" POD paskiem akcji, a nie w nim.

                 W pasku stoją rzeczy, które robi się NA Kukingu:
                 „Ugotowałem", „Zapisuję", „Gotuję". Wysłanie przepisu
                 córce na WhatsAppie wyprowadza człowieka poza serwis
                 i jest czynnością innego rodzaju — mieszanie ich w jednym
                 rzędzie kosztowałoby „Ugotowałem" pierwszeństwo, a to
                 jest najważniejszy sygnał w całym produkcie (AGENTS.md §1).

                 Widoczne także dla gościa. Osoba bez konta, która trafiła
                 tu z Google i chce wysłać przepis siostrze, jest naszym
                 najtańszym kanałem dotarcia (docs/research/AUDIENCE_50_PLUS.md),
                 a nie kimś, komu trzeba najpierw kazać się zarejestrować. --}}
            <x-podziel-sie :tresc="$recipe" />

            {{-- „Skąd ten przepis” stoi PRZED składnikami. To jest decyzja
                 produktowa, nie kolejność przypadkowa. --}}
            @if($recipe->source_note || $recipe->source_person)
                <section class="recipe-story">
                    <h2 class="mt-0 text-title-sm">Skąd ten przepis</h2>
                    @if($recipe->source_person)
                        {{-- WARTOŚĆ IDZIE DOSŁOWNIE, BEZ DOKLEJONEGO PRZYIMKA.
                             Stało tu „Po {{ … }}." — przyimek wklejony na
                             sztywno przed wolny tekst, więc „Nasze smaki"
                             dawało „Po Nasze smaki.", a wpisane „po mamie"
                             dawało „Po po mamie.". Nagłówek „Skąd ten przepis"
                             wyżej niesie to znaczenie sam, a odmiany dowolnego
                             ciągu znaków nie da się policzyć (patrz komentarz
                             nad `Recipe::attributionLine()`).

                             `Str::ucfirst()` jest wielobajtowe, więc wpisane
                             małą literą „od mamy" wygląda jak zdanie także
                             wtedy, gdy zaczyna się od „ó", „ż" albo „ś".
                             Kropki nie dokładamy: przy wpisanej kropce
                             wyszłyby dwie. --}}
                        <p><strong>{{ \Illuminate\Support\Str::ucfirst($recipe->source_person) }}</strong></p>
                    @endif
                    @if($recipe->source_note)
                        <p class="whitespace-pre-line mb-0">{{ $recipe->source_note }}</p>
                    @endif
                    @if($recipe->sourceScan)
                        <div class="mt-4">
                            <x-photo :media="$recipe->sourceScan" variant="feed" class="post-photo"
                                     tresc="przepis" :wymien-url="$edycjaZdjecPrzepisu ? $edycjaZdjecPrzepisu.'#f-source_scan' : null" />
                            <p class="meta">Kartka, z której jest ten przepis.</p>
                        </div>
                    @endif
                </section>
            @endif

            @if($recipe->source_type === 'external' && $recipe->source_url)
                <p class="meta m-0">Przepis pochodzi ze strony: <a href="{{ $recipe->source_url }}" rel="nofollow noopener">{{ $recipe->source_url }}</a></p>
            @endif
        </div>

        {{--
            Plakietki, których kit nie ma, a które są tym, czym Kuking różni
            się od bazy receptur: ile razy ktoś to ugotował i od kiedy
            przepis jest w rodzinie. Zostają POD hero, żeby nie konkurowały
            z trzema liczbami, które mówią „czy zdążę i dla ilu osób".
        --}}
        <ul class="recipe-facts">
            @if($cookedCount > 0)<li><span class="badge badge-cooked">Ugotowane {{ $cookedCount }} ×</span></li>@endif
            {{-- Odpowiedzi przy wykonaniach, nie unikalne osoby — od trzech ocen (#666).
                 Poniżej trzech jedna opinia waży za dużo, a zdanie brzmi jak
                 werdykt, którym nie jest. --}}
            @if($oceniloWykonanie >= 3)
                <li><span class="badge badge-cooked">Zrobię ponownie: {{ $zrobiaPonownie }} z {{ $oceniloWykonanie }} odpowiedzi</span></li>
            @endif
            @if($recipe->family_since_year)<li><span class="badge badge-cooked">W rodzinie od {{ $recipe->family_since_year }}</span></li>@endif
        </ul>



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
            {{-- Składniki i Przygotowanie to `sekcja-strony`, nie `card`: na tym
                 ekranie przepis JEST stroną, a te dwa bloki są jego częściami,
                 nie osobnymi kartami w strumieniu. Cień zostaje panelowi wyżej,
                 bo tam stoi „Ugotowałem", i kartom cudzych wykonań i komentarzy
                 niżej. --}}
            <section class="sekcja-strony">
                <h2>Składniki</h2>
                @if($recipe->ingredients->isEmpty())
                    <p class="meta">Autor jeszcze nie dodał składników.</p>
                @else
                    {{--
                        GRUPY SKŁADNIKÓW — „Ciasto”, „Farsz”, „Do podania”
                        (D-033, część pierwsza).

                        Układ liczy `App\Domain\Recipes\GrupySkladnikow`, ten
                        sam, co w podglądzie kreatora i w eksporcie danych:
                        składniki bez grupy na górze i bez nagłówka, grupy
                        w kolejności, w jakiej podał je autor, wiersze jednej
                        grupy pod JEDNYM nagłówkiem także wtedy, gdy leżą
                        w liście z przeplotem.

                        PRZEPIS BEZ GRUP — czyli zdecydowana większość —
                        dostaje z tego dokładnie jedną listę bez nagłówka,
                        tak jak dotąd. Grupa nie jest brakiem do uzupełnienia
                        i nic tu o niej nie wspomina, dopóki autor jej nie
                        napisał.

                        NAGŁÓWEK JEST NAGŁÓWKIEM (`<h3>` pod `<h2>Składniki`),
                        a nie pogrubionym akapitem: czytnik ekranu wypisuje
                        listę nagłówków strony i po niej się skacze. Pogrubiony
                        `<p>` wygląda tak samo, a w tej liście nie istnieje.

                        Każda grupa ma WŁASNY `<ul>`, a nie jedną listę
                        z nagłówkami w środku — `<h3>` nie jest dozwolonym
                        dzieckiem `<ul>`, a czytnik podaje liczbę pozycji na
                        starcie listy („lista, 4 pozycje”), więc osobne listy
                        mówią, ile rzeczy jest w tej części przepisu.
                    --}}
                    @foreach(\App\Domain\Recipes\GrupySkladnikow::ulozyc($recipe->ingredients) as $grupaSkladnikow)
                        @if($grupaSkladnikow['nazwa'] !== null)
                            <h3 class="naglowek-grupy">{{ $grupaSkladnikow['nazwa'] }}</h3>
                        @endif
                        <ul class="ingredient-list">
                            @foreach($grupaSkladnikow['skladniki'] as $ingredient)
                                <li>
                                    {{ $ingredient->ingredient_text }}
                                    {{-- „Bez ilości” nie określa sposobu dozowania.
                                         Pokazujemy tekst autora bez dopisków (#878). --}}
                                    @if($ingredient->note)<span class="meta"> — {{ $ingredient->note }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    @endforeach
                @endif
            </section>

            <section class="sekcja-strony">
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
                                            <x-photo :media="$step->media" variant="feed" class="post-photo"
                                                     tresc="przepis" :wymien-url="$edycjaZdjecPrzepisu ? $edycjaZdjecPrzepisu.'#f-steps-'.$loop->index.'-photo' : null" />
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
                        {{-- NAPIS MÓWI, CO JEST ZA PRZYCISKIEM, A NIE JAK NAZYWA
                             SIĘ CZYNNOŚĆ (issue #364, D-135).

                             Ekran po drugiej stronie ma nagłówek „Dopisz
                             szczegóły", gdy jest co dopisać
                             (`pages/recipes/szczegoly.blade.php`). Przycisk
                             mówił do tej pory zawsze „Edytuj przepis" — a to
                             dla autora, który właśnie opublikował przepis
                             z samym zdjęciem i tytułem, brzmi jak poprawianie
                             błędu, nie jak zaproszenie. Zaproszenie padało
                             dotąd RAZ, w komunikacie po publikacji, i znikało
                             razem z nim.

                             Ta sama trasa i ta sama Policy — zmienia się
                             wyłącznie napis, i zmienia się na prawdziwy.
                             `CoMoznaDopisac` pyta o dziesięć pól, o zdjęcie
                             główne oraz o to, czy przepis ma choć jeden
                             składnik i choć jeden krok; przy wypełnionym
                             wszystkim napis wraca do „Edytuj przepis", bo
                             wtedy dopisywać nie ma czego i zaproszenie byłoby
                             kłamstwem.

                             Reguła stoi w domenie, a nie w tym widoku, bo
                             odpowiada na nią też komunikat po publikacji
                             (`RecipeController::store()`) — dwie odpowiedzi na
                             to samo pytanie muszą być tą samą odpowiedzią. --}}
                        <a class="btn btn-secondary" href="{{ route('recipes.edit', $recipe->slug) }}">{{ \App\Domain\Recipes\CoMoznaDopisac::jest($recipe) ? 'Dopisz szczegóły' : 'Edytuj przepis' }}</a>
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
                        {{ $cookedCount }} {{ \App\Support\Odmiana::rzeczownik($cookedCount, 'wykonanie', 'wykonania', 'wykonań') }}
                        @if($oceniloWykonanie >= 3)
                            · {{ (int) round($zrobiaPonownie / $oceniloWykonanie * 100) }}% odpowiedzi: „Zrobię ponownie”
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
                    <p class="mb-0">Twoje wykonanie będzie pierwsze.</p>
                </x-empty-state>
            @endif
        </section>

        @if(auth()->id() === $recipe->author_id)
            <div class="danger-zone kolumna-czytania">
                <x-confirm-button
                    :action="route('recipes.destroy', $recipe->slug)"
                    label="Usuń ten przepis"
                    :question="'Na pewno usunąć przepis „'.$recipe->title.'”? Wykonania i komentarze innych osób też przestaną być widoczne.'" />
            </div>
        @endif

        <div class="kolumna-czytania">
            <x-comment-thread :comments="$komentarze" :ile="$komentarzyRazem" :action="route('recipes.comment', $recipe->slug)" />
        </div>
    </article>
</x-layout>
