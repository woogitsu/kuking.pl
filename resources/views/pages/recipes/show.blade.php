@php
    $isPublic = $recipe->visibility === 'public' && $recipe->isPublished();
    $total = $recipe->totalMinutes();
    // Jedna odpowiedź na „ile porcji" dla znaczka i dla structured data
    // (audyt A28) — dwa osobne teksty to dwie okazje do rozjazdu.
    $porcje = $recipe->servingsLabel();
    // „Dla pomocnika” (#2345): ten sam wydruk (`?druk=1`), krótsza kartka
    // — bez opisu i „Skąd ten przepis”, z liczbą porcji i opcjonalnym kodem
    // QR (`&qr=1`). Zero nowych danych; to nadal strona pod RecipePolicy::view.
    $dlaPomocnika = request()->boolean('druk') && request()->query('dla') === 'pomocnika';
    $kartaQr = $dlaPomocnika ? app(\App\Domain\Sharing\KartaZKodemQr::class) : null;
    // Kod QR tylko dla przepisu, który zobaczy GOŚĆ — ta sama bramka co karta #2349.
    $qrMozliwy = $kartaQr !== null && $kartaQr->przepisDostepny($recipe);
    $qrNaKartce = $qrMozliwy && request()->boolean('qr');
    $adresQr = $qrNaKartce ? $kartaQr->adresPrzepisu($recipe) : null;
    $adresDruku = fn (bool $pomocnik, bool $qr = false): string => route('recipes.show', array_filter([
        'recipe' => $recipe->slug,
        'druk' => 1,
        'dla' => $pomocnik ? 'pomocnika' : null,
        'qr' => $pomocnik && $qr ? 1 : null,
        'porcje' => $wyborSztuk->przeliczone() ? null : $zapamietanePorcje->parametrBiezacego(),
        'sztuki' => $wyborSztuk->doAdresu(),
    ], fn ($wartosc) => $wartosc !== null)).'#jak-wydrukowac';
    $parametrPorcjiGotowania = $wyborPorcji->przeliczone()
        ? $wyborPorcji->doAdresu((float) $wyborPorcji->wybrane)
        : null;
    $adresGotowania = route('cooking.show', array_filter([
        'recipe' => $recipe->slug, 'porcje' => $parametrPorcjiGotowania,
    ], fn ($wartosc) => $wartosc !== null));
    // Dokąd iść po wymianę odrzuconego zdjęcia (#752). Pyta Policy, tak jak
    // przycisk edycji niżej — przepis ukryty przez moderację edycji nie ma.
    // Konto zawieszone edycję otworzy, ale jej nie zapisze
    // (`EnsureAccountIsActive`), więc link byłby martwym przyciskiem.
    $edycjaZdjecPrzepisu = auth()->user()?->isActive() && auth()->user()->can('update', $recipe)
        ? route('recipes.edit', $recipe->slug)
        : null;
    // „Moja wersja" (issue #23, D-301): oryginał widoczny dla GOŚCIA, czyli
    // taki, który sam jest w indeksie — tylko on może trafić do `isBasedOn`.
    $oryginalPubliczny = \App\Domain\Recipes\MojaWersja::oryginalDlaWidza($recipe, null);
    $wersje ??= null;
@endphp
<x-layout
    :title="$recipe->title"
    :description="\Illuminate\Support\Str::limit($recipe->summary ?? $recipe->title, 155)"
    :noindex="! $isPublic"
    {{-- Wersja zbyt podobna do publicznego oryginału: strona dla ludzi, ale
         poza indeksem, z `follow`, żeby link do oryginału dalej prowadził
         (docs/seo/SEO_TECHNICAL.md §1.4 pkt 4, D-301). `canonical` zostaje
         na sobie — wersja nie jest duplikatem adresu, tylko osobnym przepisem. --}}
    :noindexFollow="$isPublic && ! ($wersjaDoIndeksu ?? true)"
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
            {{--
                `Recipe` TYLKO ZE ZDJĘCIEM (#1005). Google wymaga `image`, a bez
                niego obiekt nie kwalifikuje się do wyniku rozszerzonego
                i ląduje jako błąd w Search Console. Zdjęcie jest w Kuking
                opcjonalne i przez chwilę po wgraniu nie jest `ready` — wtedy
                lepiej nie deklarować typu, którego nie umiemy wypełnić.
                Logo zamiast dania odpada: obraz ma przedstawiać przepis.
                `BreadcrumbList` niżej zostaje zawsze.
            --}}
            @if($recipe->heroMedia?->isReady() && $recipe->heroMedia->maWariantDoPokazania('large'))
            @php
                /*
                 * KALORIE NA PORCJĘ (#1996) — TYLKO TE, KTÓRE STRONA POKAZUJE.
                 * Te same warunki co `x-wartosci-odzywcze`: autor nie ukrył
                 * sekcji, są składniki, kalkulator uznał wynik za wiarygodny
                 * (pokrycie ≥ 90%) i znamy liczbę porcji. Liczba i zaokrąglenie
                 * z tego samego `kcalDoPokazania()`. Bez tego pola `nutrition`
                 * nie ma wcale (Google: dane muszą zgadzać się z treścią).
                 */
                $kcalNaPorcje = ($recipe->pokazuj_wartosci_odzywcze ?? true) && $recipe->ingredients->isNotEmpty()
                    ? app(\App\Domain\Recipes\Odzywcze\KalkulatorWartosci::class)->policz($recipe)->kcalNaPorcjeDoDanychStrukturalnych()
                    : null;
                $recipeJsonLd = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Recipe',
                'name' => $recipe->title,
                'description' => $recipe->summary,
                'datePublished' => $recipe->published_at?->toDateString(),
                /*
                 * DATA ZMIANY TREŚCI, NIE ZAPISU WIERSZA (#2014).
                 *
                 * `tresc_zmieniona_at` przestawia tylko `PublishRecipe` i tylko
                 * przy realnej zmianie treści albo zdjęć — nie moderacja, nie
                 * widoczność, nie zapis bez zmian (`updated_at` przesuwają
                 * wszystkie trzy). `NULL` (przepis sprzed kolumny) i data
                 * sprzed publikacji to „nie wiemy": wtedy pola nie ma, bo
                 * zgadnięta data byłaby niezgodna z treścią (`sd-policies`).
                 * Format jak `datePublished`.
                 */
                'dateModified' => $recipe->dataZmianyTresci()?->toDateString(),
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
                 *
                 * #900 (D-254): dawny adres FTP/SSH może zostać w bazie, ale
                 * nie jest ani linkiem, ani adresem strony — tu ten sam
                 * warunek HTTP/HTTPS co przy linku niżej.
                 */
                /*
                 * „MOJA WERSJA" (issue #23, D-301): `isBasedOn` wskazuje
                 * ORYGINAŁ w serwisie — ten sam, który podpis nad tytułem
                 * pokazuje gościowi. Oryginał niewidoczny dla gościa nie trafia
                 * tu wcale: dane strukturalne nie mogą obiecywać adresu, pod
                 * którym wyszukiwarka dostanie 403 albo 404.
                 */
                'isBasedOn' => $oryginalPubliczny !== null
                    ? $oryginalPubliczny->url()
                    : ($recipe->source_type === \App\Models\Recipe::SOURCE_EXTERNAL
                        && \Illuminate\Support\Str::isUrl((string) $recipe->source_url, ['http', 'https'])
                    ? $recipe->source_url
                    : null),
                'image' => [$recipe->heroMedia->url('large')],
                'recipeYield' => $porcje,
                'nutrition' => $kcalNaPorcje !== null ? [
                    '@type' => 'NutritionInformation',
                    'calories' => $kcalNaPorcje.' kcal',
                ] : null,
                'prepTime' => $recipe->prep_minutes ? 'PT'.$recipe->prep_minutes.'M' : null,
                'cookTime' => $recipe->cook_minutes ? 'PT'.$recipe->cook_minutes.'M' : null,
                'totalTime' => $recipe->totalTimeIso(),
                'recipeIngredient' => $recipe->ingredients->pluck('ingredient_text')->all(),
                /*
                 * ZDJĘCIE KROKU TYLKO WTEDY, GDY STRONA JE POKAZUJE (#1370).
                 *
                 * Warunek i wariant są DOKŁADNIE te, których używa lista
                 * kroków niżej (`<x-photo :media="$step->media"
                 * variant="feed">` pyta `maWariantDoPokazania('feed')`).
                 * Krok ze zdjęciem jeszcze nieprzetworzonym albo w trakcie
                 * kasowania nie dostaje `image` — dane strukturalne nie
                 * mogą obiecywać obrazu, którego człowiek nie widzi.
                 * `url()` wskazuje przetworzony wariant publiczny, nigdy
                 * oryginał, i jest bezwzględny (`route()` z APP_URL).
                 * Bez sztucznego `name` z numeru kroku — Google go nie
                 * wymaga, a „Krok 3" nic nie mówi.
                 */
                'recipeInstructions' => $recipe->steps->map(fn ($step) => array_filter([
                    '@type' => 'HowToStep',
                    'position' => $step->position + 1,
                    'text' => $step->instruction,
                    'image' => $step->media?->maWariantDoPokazania('feed') ? $step->media->url('feed') : null,
                ], static fn ($value) => $value !== null))->all(),
                'interactionStatistic' => $cookedCount > 0 ? [
                    '@type' => 'InteractionCounter',
                    'interactionType' => 'https://schema.org/CookAction',
                    'userInteractionCount' => $cookedCount,
                ] : null,
                'inLanguage' => 'pl-PL',
            ], static fn ($value) => $value !== null && $value !== []);
            @endphp
            <x-json-ld :data="$recipeJsonLd" />
            @endif

            @php
                // Ścieżka przepisu zostaje, jaka była — etykieta i cel drugiego
                // poziomu czekają na #667. Kodowanie wspólne z wpisami,
                // pytaniami i profilami (`Okruszki`, #1033).
                $breadcrumbJsonLd = \App\Support\Okruszki::jsonLd([
                    ['nazwa' => 'Kuking', 'url' => route('landing')],
                    ['nazwa' => 'Świeżo z Kuking', 'url' => route('discover')],
                    ['nazwa' => $recipe->title, 'url' => null],
                ]);
            @endphp
            <x-json-ld :data="$breadcrumbJsonLd" />
        @endif
    </x-slot:head>

    {{-- Bezpośredni header zachowuje semantykę i pomiar typografii portu. --}}
    <article @class(['stack', 'przepis-uklad', 'marka-przepis', 'dla-pomocnika' => $dlaPomocnika])>
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
            {{-- Kartka dla pomocnika (#2345) nie ma nigdzie „Skąd ten przepis”, więc i nad tytułem
                 zostaje sama nazwa autora, bez dopisku z osobą źródła. --}}
            <p class="meta">{{ $dlaPomocnika ? $recipe->author->displayName() : $recipe->attributionLine() }}</p>
            <x-na-podstawie-przepisu :recipe="$recipe" />
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
                <p class="notice kolumna-czytania"><strong>To jest szkic.</strong> Widzisz go tylko Ty. Kliknij „{{ \App\Domain\Recipes\CoMoznaDopisac::jest($recipe) ? 'Dopisz szczegóły' : 'Edytuj przepis' }}”, żeby dokończyć i opublikować.</p>
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
                        @if(($obserwuje ?? false) && auth()->user()->can('unfollow', $recipe->author))
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
                @guest
                    <a class="btn btn-secondary" href="{{ route('register', ['follow_user' => $recipe->author_id, 'follow_recipe' => $recipe->slug]) }}">Załóż konto, żeby obserwować autora</a>
                    <a class="btn btn-quiet" href="{{ route('login', ['follow_user' => $recipe->author_id, 'follow_recipe' => $recipe->slug]) }}">Zaloguj się do swojego konta</a>
                @endguest
            </div>
            {{-- Tylko na papierze (#765, resources/css/wydruk-przepisu.css):
                 z kartki trzeba umieć wrócić do przepisu. Adres kanoniczny,
                 nie bieżący z parametrami stronicowania komentarzy; dostęp
                 i tak rozstrzyga RecipePolicy przy wejściu. --}}
            <p class="meta m-0 przepis-adres-druk">Adres przepisu: {{ route('recipes.show', $recipe->slug) }}</p>
                {{-- Opis i dane autora należą do tekstowej połowy hero. --}}
        @if($recipe->summary && ! $dlaPomocnika)
            <p class="text-lead kolumna-czytania">{{ $recipe->summary }}</p>
        @endif
            @if($total || $porcje || $recipe->yieldLabel() || $recipe->difficultyLabel())
                <ul class="przepis-liczby">
                    @if($total)
                        <li class="przepis-liczba">
                            <x-ikona nazwa="clock" :rozmiar="26" />
                            <div><strong>Około {{ \App\Support\Czas::czasPrzepisu($total) }}</strong><span>Czas</span></div>
                        </li>
                    @endif
                    @if($porcje && ! $dlaPomocnika)
                        <li class="przepis-liczba">
                            <x-ikona nazwa="users" :rozmiar="26" />
                            <div><strong>{{ $porcje }}</strong><span>Ilość</span></div>
                        </li>
                    @endif
                    @if($recipe->yieldLabel() && ! $dlaPomocnika)
                        <li class="przepis-liczba">
                            <x-ikona nazwa="chef" :rozmiar="26" />
                            <div><strong>{{ $recipe->yieldLabel() }}</strong><span>Gotowe sztuki</span></div>
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
            {{-- Czas łączny podany przez źródło importu (#2572): osobne zdanie,
                 nie mnożymy czasów — tylko gdy autor nie ma własnych prep+cook. --}}
            @if($recipe->czas_laczny_zrodla_minut && ! $total)
                <p class="przepis-czas-zrodla kolumna-czytania" data-czas-zrodla="{{ $recipe->czas_laczny_zrodla_minut }}">Źródło podaje łącznie: około {{ \App\Support\Czas::czasPrzepisu($recipe->czas_laczny_zrodla_minut) }}.</p>
            @endif
            {{-- Typowy czas z wykonań (#2067): dwa osobne zdania, dwa źródła.
                 Zwykły tekst, bez ikony i bez „szybciej niż autor”. Poniżej
                 progu 5 osób nie ma nic — także zdania o braku danych. --}}
            @if(($typowyCzas ?? null) !== null)
                <p class="przepis-typowy-czas kolumna-czytania" data-typowy-czas="{{ $typowyCzas->minuty }}">
                    @if($total)Autor podaje około {{ \App\Support\Czas::czasPrzepisu($total) }}. @endif{{ $typowyCzas->zdanie() }}
                </p>
            @endif
            @if($dlaPomocnika && $wyborSztuk->przeliczone())
                {{-- Wybór sztuk (#2645) zastępuje porcje: jedna podstawa. --}}
                <p class="druk-pomocnik-porcje m-0"><strong>Ilość: {{ $wyborSztuk->etykieta() }}</strong> <span class="meta">(w przepisie autora: {{ $wyborSztuk->etykietaAutora() }})</span></p>
            @elseif($dlaPomocnika && ($wyborPorcji->dostepny() || $porcje))
                {{-- Kartka dla pomocnika: liczba porcji WIELKO, z wyborem z adresu
                     (`?porcje=`), nie zawsze z przepisu autora. --}}
                <p class="druk-pomocnik-porcje m-0"><strong>Ilość: {{ $wyborPorcji->dostepny() ? \App\Domain\Recipes\Porcje\WyborPorcji::etykieta($wyborPorcji->wybrane) : $porcje }}</strong>@if($wyborPorcji->przeliczone()) <span class="meta">(w przepisie autora: {{ \App\Domain\Recipes\Porcje\WyborPorcji::etykieta($wyborPorcji->zPrzepisu) }})</span>@endif</p>
            @endif
            {{-- Kwota autora skaluje się tylko dla tego samego, sprawdzonego wyboru
                 porcji co składniki (D-286). Nie skaluje to przedziału z cennika. --}}
            @php
                $kosztAutora = $recipe->costLabel();
                if ($kosztAutora !== null && $wyborSztuk->przeliczone()) {
                    // Koszt całości rośnie proporcjonalnie do sztuk (#2645).
                    $przeliczonyKoszt = \App\Domain\Recipes\KosztPrzepisu::naPorcje(
                        (float) $recipe->estimated_cost_pln,
                        (float) $wyborSztuk->zPrzepisu,
                        (float) $wyborSztuk->wybrane,
                    );
                    if ($przeliczonyKoszt !== null) {
                        $kosztAutora = \App\Domain\Recipes\KosztPrzepisu::zdaniePrzeliczone($przeliczonyKoszt);
                    }
                } elseif ($kosztAutora !== null && $wyborPorcji->przeliczone()) {
                    $przeliczonyKoszt = \App\Domain\Recipes\KosztPrzepisu::naPorcje(
                        (float) $recipe->estimated_cost_pln,
                        $wyborPorcji->zPrzepisu,
                        (float) $wyborPorcji->wybrane,
                    );
                    if ($przeliczonyKoszt !== null) {
                        $kosztAutora = \App\Domain\Recipes\KosztPrzepisu::zdaniePrzeliczone($przeliczonyKoszt);
                    }
                }
            @endphp
            @if($kosztAutora !== null)
                <p class="przepis-koszt kolumna-czytania" data-koszt-autora="{{ $recipe->estimated_cost_pln }}">{{ $kosztAutora }}</p>
            @elseif(($szacunekKosztu ?? null) !== null)
                {{-- Bez kwoty autora: przedział z cen GUS albo zdanie, dlaczego
                     go nie ma (D-286, część 2). Zawsze „orientacyjny", zawsze
                     ze źródłem i z zastrzeżeniem o sklepie. --}}
                <p class="przepis-koszt kolumna-czytania" data-koszt-szacunek="{{ $szacunekKosztu->jestPrzedzial() ? 'przedzial' : 'brak' }}">{{ $szacunekKosztu->zdanie() }}</p>
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
                    @can('cook', $recipe)
                        <a class="btn btn-primary" href="{{ route('cooked.create', $recipe->slug) }}">{{ \App\Support\Forma::dla(auth()->user(), 'Ugotowałam', 'Ugotowałem', 'Ugotowałem') }}</a>
                    @endcan
                    @if($isSaved)
                        {{--
                            WYJĘCIE MÓWI, SKĄD WYJMUJE (issue #775).

                            Ten formularz wysyłał samo DELETE, bez wskazania
                            zeszytu — a akcja po drugiej stronie kasowała
                            przepis ze WSZYSTKICH zeszytów tej osoby. Człowiek
                            z pięcioma zeszytami klikał „Usuń z zeszytu”
                            i tracił pięć wierszy razem z notatkami własnymi,
                            nie widząc nigdzie, że tak się stanie.

                            Są dwa przypadki i różni je to, czy w ogóle jest
                            co ujawniać:

                             • JEDEN ZESZYT — wiadomo, z którego wyjmujemy,
                               więc mówimy to wprost i wysyłamy `collection_id`.
                               Nic poza tym zeszytem nie zostanie ruszone,
                               nawet gdyby przepis trafił do kolejnego między
                               narysowaniem strony a kliknięciem;

                             • KILKA ZESZYTÓW — nie wiadomo, o który chodzi,
                               więc zakres zostaje szeroki, ale STOI NAPISANY
                               NAD PRZYCISKIEM, a nie dopiero w komunikacie po
                               fakcie. `aria-describedby` wiąże to zdanie
                               z przyciskiem, żeby czytnik ekranu przeczytał
                               je razem z nim, a nie osobno gdzieś wyżej.

                            Napis na przycisku zostaje ten sam w obu gałęziach.
                            Zakres niosą `collection_id` i zdanie obok, nie
                            etykieta — dzięki temu ekran zeszytu może nazwać
                            swój przycisk po swojemu, a ta strona nie musi się
                            o to spierać.
                        --}}
                        @php $zeszytyTegoPrzepisu = $zeszytyZPrzepisem ?? collect(); @endphp
                        <form method="POST" action="{{ route('collections.unsave', $recipe->slug) }}">
                            @csrf @method('DELETE')
                            @if($zeszytyTegoPrzepisu->count() === 1)
                                <input type="hidden" name="collection_id" value="{{ $zeszytyTegoPrzepisu->first()->id }}">
                                <p class="pomoc" id="zakres-wyjecia-{{ $recipe->getKey() }}">Masz ten przepis w zeszycie „{{ $zeszytyTegoPrzepisu->first()->name }}”.</p>
                            @elseif($zeszytyTegoPrzepisu->count() > 1)
                                <p class="notice" id="zakres-wyjecia-{{ $recipe->getKey() }}">Uwaga: ten przepis leży w {{ $zeszytyTegoPrzepisu->count() }} Twoich zeszytach, a ten przycisk zdejmie go ze wszystkich Twoich zeszytów — razem z notatkami. Zanim to zrobimy, zapytamy o potwierdzenie i pozwolimy wybrać jeden zeszyt.</p>
                            @endif
                            <button class="btn btn-secondary" type="submit"
                                @if($zeszytyTegoPrzepisu->isNotEmpty()) aria-describedby="zakres-wyjecia-{{ $recipe->getKey() }}" @endif
                            ><x-ikona nazwa="save" /> Usuń z zeszytu</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('collections.save', $recipe->slug) }}">
                            @csrf
                            @php $publicznyCel = app(\App\Http\Support\ZeszytyZZadania::class)->publicznyDomyslny(request()); @endphp
                            @if($publicznyCel)
                                {{-- Cel szybkiego zapisu jest publiczny — mówimy to przy przycisku (issue #1400). --}}
                                <p class="pomoc" id="cel-zapisu-{{ $recipe->getKey() }}">Zapiszemy w zeszycie „{{ $publicznyCel->name }}”. Ten zeszyt widzą inne zalogowane osoby.</p>
                            @endif
                            <button class="btn btn-secondary" type="submit" @if($publicznyCel) aria-describedby="cel-zapisu-{{ $recipe->getKey() }}" @endif><x-ikona nazwa="save" /> Zapisuję</button>
                        </form>
                    @endif
                    <x-wybor-zeszytu :action="route('collections.save', $recipe->slug)" :wiersz="'przepis-'.$recipe->getKey()" :content="$recipe" :otwarty="request()->boolean(\App\Support\ZamiarZapisu::ROZWIN)" />
                    {{-- Planer tygodnia (#27, D-310): prywatny, obok „Zapisuję”. --}}
                    <x-dodaj-do-planera :recipe="$recipe" />
                @else
                    <a class="btn btn-primary" href="{{ route('register') }}">Załóż konto, żeby dać znać autorowi</a>
                    {{-- „Zapisz do zeszytu” dla gościa (#2028): link niesie UUID przepisu,
                         po rejestracji wracamy tu z rozwiniętym wyborem zeszytu. --}}
                    <a class="btn btn-secondary" href="{{ route('register', [\App\Support\ZamiarZapisu::PARAMETR => $recipe->getKey()]) }}"><x-ikona nazwa="save" /> Zapisz do zeszytu</a>
                    <a class="btn btn-quiet" href="{{ route('login', [\App\Support\ZamiarZapisu::PARAMETR => $recipe->getKey()]) }}">Masz konto? Zaloguj się i zapisz</a>
                @endauth

                {{--
                    „Gotuję" — tryb pełnoekranowy (issue #24). Widoczny
                    tylko, gdy przepis w ogóle ma kroki: bez nich nie
                    miałby czego pokazać, a kontroler i tak zawraca
                    z czytelnym komunikatem, gdyby ktoś trafił tu wprost.
                --}}
                @if($recipe->steps->isNotEmpty())
                    <a class="btn btn-secondary" href="{{ $adresGotowania }}">Gotuję — pokaż kroki na cały ekran</a>
                    {{-- Kolejka kilku potraw (#2379): przycisk odkrywa skrypt. --}}
                    <x-kolejka-dodaj :recipe="$recipe" />
                @endif

                {{--
                    „DRUKUJ PRZEPIS” (#765). Kartka leży obok blatu, a Ctrl+P
                    nie jest czymś, co nasza grupa zna na pamięć — stąd
                    widoczny przycisk. To ZWYKŁY ODNOŚNIK do tej samej strony
                    z `?druk=1`: skrypt (`resources/js/drukuj-przepis.js`)
                    zamienia kliknięcie w `window.print()`, a bez skryptu
                    człowiek ląduje przy instrukcji niżej, nie przy martwym
                    przycisku (D-053). Na papier przycisk nie idzie — `main .btn`
                    chowa `wydruk-przepisu.css`.
                --}}
                <a class="btn btn-secondary" href="{{ $adresDruku(false) }}" rel="nofollow" data-drukuj-przepis>Drukuj przepis</a>
                {{-- „Dla pomocnika” (#2345): krótsza kartka na blat, bez skryptu —
                     zwykły odnośnik do instrukcji i wyboru kodu QR niżej. --}}
                <a class="btn btn-secondary" href="{{ $adresDruku(true) }}" rel="nofollow">Drukuj dla pomocnika</a>
            </div>
            @if(request()->boolean('druk'))
                <div class="notice druk-podpowiedz" id="jak-wydrukowac" role="status">
                    <p class="m-0"><strong>Jak wydrukować ten przepis:</strong></p>
                    <p class="m-0">Na komputerze naciśnij razem klawisze <kbd>Ctrl</kbd> i <kbd>P</kbd> (na komputerze Apple: <kbd>Cmd</kbd> i <kbd>P</kbd>).</p>
                    <p class="m-0">Na telefonie otwórz menu przeglądarki (trzy kropki albo „Udostępnij”) i wybierz „Drukuj”.</p>
                    @if($dlaPomocnika)
                        <p class="m-0">Na kartce dla pomocnika będzie to, co potrzebne przy blacie: porcje, składniki i kroki dużym drukiem — bez opisu i bez rodzinnej historii przepisu.</p>
                        @if($qrMozliwy)
                            @if($qrNaKartce)
                                <p class="m-0">Na kartce będzie kod QR do tego przepisu.</p>
                                <p class="m-0"><a class="btn btn-secondary" href="{{ $adresDruku(true, false) }}" rel="nofollow">Bez kodu QR</a></p>
                            @else
                                <p class="m-0"><a class="btn btn-secondary" href="{{ $adresDruku(true, true) }}" rel="nofollow">Dodaj kod QR do kartki</a></p>
                            @endif
                        @else
                            <p class="m-0">Kod QR jest tylko dla przepisów, które widzi każdy. Ten przepis go nie ma.</p>
                        @endif
                        <p class="m-0">Liczbę porcji zmienisz przyciskami „Mniej” i „Więcej” nad składnikami.</p>
                        <p class="m-0"><a class="btn btn-secondary" href="{{ $adresDruku(false) }}" rel="nofollow">Wróć do zwykłego wydruku</a></p>
                    @else
                        <p class="m-0">Na kartce będzie sam przepis — bez menu, przycisków i komentarzy.</p>
                    @endif
                </div>
            @endif

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

            {{-- „Historia zmian” tylko przy co najmniej dwóch zapisanych
                 wersjach (issue #2024) — przy jednej nie ma czego porównać.
                 Bramka w `HistoriaWersji::pokazacLink()`, ta sama co na
                 ekranach historii. --}}
            @if($historiaWersji)
                <p class="m-0"><a class="btn btn-secondary" href="{{ route('recipes.history', $recipe->slug) }}">Historia zmian</a></p>
            @endif

            {{-- „Skąd ten przepis” stoi PRZED składnikami. To jest decyzja
                 produktowa, nie kolejność przypadkowa. --}}
            @if(($recipe->source_note || $recipe->source_person || $recipe->sourceScan) && ! $dlaPomocnika)
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

            @if($recipe->source_type === 'external' && $recipe->source_url && ! $dlaPomocnika)
                <p class="meta m-0">Przepis pochodzi ze strony:
                    @if(\Illuminate\Support\Str::isUrl($recipe->source_url, ['http', 'https']))
                        <a href="{{ $recipe->source_url }}" rel="nofollow noopener">{{ $recipe->source_url }}</a>
                    @else
                        {{ $recipe->source_url }}
                    @endif
                </p>
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
            <section class="sekcja-strony" id="skladniki">
                <h2>Składniki</h2>
                @if($recipe->ingredients->isEmpty())
                    <p class="meta">Autor jeszcze nie dodał składników.</p>
                @else
                    @unless($wyborSztuk->przeliczone())
                        @include('pages.recipes._wybor-porcji', ['wyborPorcji' => $wyborPorcji, 'zapamietanePorcje' => $zapamietanePorcje, 'recipe' => $recipe, 'dlaPomocnika' => $dlaPomocnika, 'qrNaKartce' => $qrNaKartce])
                        @include('pages.recipes._zapamietaj-porcje', ['wyborPorcji' => $wyborPorcji, 'zapamietanePorcje' => $zapamietanePorcje, 'recipe' => $recipe])
                    @endunless
                    @include('pages.recipes._wybor-sztuk', ['wyborSztuk' => $wyborSztuk, 'recipe' => $recipe, 'dlaPomocnika' => $dlaPomocnika, 'qrNaKartce' => $qrNaKartce])
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
                                @php($przeliczony = $wyborSztuk->przeliczone() ? $wyborSztuk->przelicz($ingredient) : $wyborPorcji->przelicz($ingredient))
                                <li>
                                    {{-- Przeliczona ilość jest pogrubiona, reszta to zdanie
                                         autora co do znaku (D-284). Bez przeliczenia —
                                         dokładnie `ingredient_text`. Jedna linia, żeby Blade
                                         nie wstawił spacji w środek „300 g”. --}}
                                    @if($przeliczony->zmieniony){{ $przeliczony->przed }}<strong class="skladnik-przeliczony">{{ $przeliczony->ilosc }}</strong>{{ $przeliczony->po }}@else{{ $ingredient->ingredient_text }}@endif
                                    @if($przeliczony->nieprzeliczony)<span class="meta" data-skladnik-suma> — {{ \App\Domain\Recipes\Porcje\PrzeliczonySkladnik::UWAGA_SUMA }}</span>@endif
                                    {{-- „Bez ilości” nie określa sposobu dozowania.
                                         Pokazujemy tekst autora bez dopisków (#878).
                                         BRAK DOPISKU JEST CELOWY (D-232): ten ekran
                                         jest tekstem autora co do znaku. Tryb gotowania
                                         świadomie robi to inaczej — nie „ujednolicaj”. --}}
                                    @if($ingredient->note)<span class="meta"> — {{ $ingredient->note }}</span>@endif
                                    {{-- Zamiennik od autora (D-284), osobną linią pod
                                         składnikiem — tekstem ≥ 18 px, nie drobnym dopiskiem. --}}
                                    @if($ingredient->substitutes)<span class="skladnik-zamiennik">Zamiast tego: {{ $ingredient->substitutes }}</span>@endif
                                    {{-- Równoważniki miar na żądanie (#2533): tylko masa↔masa i
                                         objętość↔objętość, z ilości po wybranych porcjach.
                                         Nie na kartce do druku (`?druk=1`): papier nie rozwija
                                         bloku, a zamknięte „Przelicz” wlazłoby w tekst składnika. --}}
                                    @unless($ingredient->no_amount || request()->boolean('druk'))
                                        @php($rownowazniki = \App\Domain\Recipes\Porcje\PrzeliczMiare::dla($przeliczony->tekst()))
                                        @if($rownowazniki !== [])
                                            <details class="skladnik-przelicz" data-przelicz-miare>
                                                <summary>Przelicz</summary>
                                                <p>{{ implode(' = ', $rownowazniki) }}</p>
                                                <p class="meta">To podpowiedź przy ilości, którą widzisz powyżej. Tekst przepisu zostaje bez zmian.</p>
                                            </details>
                                        @endif
                                    @endunless
                                </li>
                            @endforeach
                        </ul>
                    @endforeach
                    {{-- Lista zakupów (#27, etap 2, D-333): kopiuje linie składników
                         tak, jak stoją wyżej — bez przeliczania porcji, bez sumowania.
                         Zwykły formularz; gość nie ma listy, więc nie widzi przycisku. --}}
                    @auth
                        <form class="mt-4" method="POST" action="{{ route('shopping.recipe.store', $recipe->slug) }}">
                            @csrf
                            <x-zakupy-wybor-listy wiersz="przepis" />
                            <button class="btn btn-secondary mt-3" type="submit">Dodaj składniki do listy zakupów</button>
                        </form>
                    @endauth
                @endif
                {{-- Alergeny według autora (#1902, D-333): stały blok pod składnikami,
                     tylko przy włączonej fladze; każdy przepis ma jeden z trzech wariantów. --}}
                @if(config('kuking.alergeny.wlaczone'))
                    <x-alergeny.blok :recipe="$recipe" />
                @endif
                {{-- Szacunkowe wartości odżywcze (D-299): pod składnikami,
                     bo liczą się z nich. Komponent sam nic nie pokazuje,
                     gdy składników nie ma albo autor sekcję ukrył. --}}
                <x-wartosci-odzywcze :recipe="$recipe" />
            </section>

            <section class="sekcja-strony">
                <h2>Przygotowanie</h2>
                @if($recipe->steps->isEmpty())
                    <p class="meta">Autor jeszcze nie opisał przygotowania.</p>
                @else
                    {{-- D-017: numer i akapit, BEZ tytułu kroku z kitu.
                         Autor pisze jeden ciąg zdań i nie ma skąd wziąć
                         tytułu, którego nie napisał. --}}
                    {{-- Nazwane etapy (#2652): bez ani jednej nazwy to jedna grupa
                         bez nagłówka, czyli ta sama lista co dawniej. Numer kroku
                         zostaje numerem instrukcji w całym przepisie. --}}
                    @foreach(\App\Domain\Recipes\EtapyPrzygotowania::grupy($recipe->steps) as $etapPrzygotowania)
                        @if($etapPrzygotowania['nazwa'] !== null)
                            <h3 class="naglowek-grupy">{{ $etapPrzygotowania['nazwa'] }}</h3>
                        @endif
                        <ol class="step-list">
                            @foreach($etapPrzygotowania['kroki'] as $indeksKroku => $step)
                                <li>
                                    <span class="step-number" aria-hidden="true">{{ $step->position + 1 }}</span>
                                    <div>
                                        <span class="visually-hidden">Krok {{ $step->position + 1 }}.</span>
                                        <p class="m-0 whitespace-pre-line">{{ $step->instruction }}</p>
                                        @if($step->timerLabel())
                                            <p class="m-0">Czas kroku: {{ $step->timerLabel() }}</p>
                                        @endif
                                        {{-- „Zapytaj o ten krok” (#2556): zwykły link, bez JS. Tylko dla
                                             konta, które może użyć zwykłego formularza komentarza, i nie dla
                                             autora przepisu (sam siebie nie zapyta). Prowadzi do formularza
                                             pod przepisem z cytatem w polu; niczego nie wysyła. --}}
                                        @if($mozeZapytacOKrok)
                                            <p class="m-0 mt-2"><a class="btn btn-quiet" href="{{ route('recipes.show', ['recipe' => $recipe->slug, \App\Support\CytatKroku::PARAMETR => $step->position + 1]) }}#nowy-komentarz">Zapytaj o ten krok<span class="visually-hidden"> (krok {{ $step->position + 1 }})</span></a></p>
                                        @endif
                                        @if($step->media)
                                            <div class="mt-3 max-w-[20rem]">
                                                {{-- Opis dla czytnika (issue #1304): własny opis autora,
                                                     a bez niego kontekst kroku zamiast pustego `alt`. --}}
                                                <x-photo :media="$step->media" variant="feed" class="post-photo"
                                                         :alt="$step->media->alt_text ?: 'Zdjęcie do kroku '.($step->position + 1)"
                                                         tresc="przepis" :wymien-url="$edycjaZdjecPrzepisu ? $edycjaZdjecPrzepisu.'#f-steps-'.$indeksKroku.'-photo' : null" />
                                            </div>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endforeach
                @endif
            </section>
        </div>

        @if($qrNaKartce)
            {{-- Kod QR na kartce dla pomocnika (#2345). Ten sam generator i ten
                 sam adres kanoniczny co karta #2349: nigdy adres z żądania,
                 więc bez `?druk=1`, `qr` i `porcje`. Tylko przepis widoczny dla gościa. --}}
            <section class="druk-pomocnik-qr" aria-labelledby="druk-pomocnik-qr-tytul">
                <h2 id="druk-pomocnik-qr-tytul">Ten przepis w telefonie</h2>
                <div class="druk-pomocnik-qr-kod" role="img" aria-label="Kod QR z adresem: {{ $adresQr }}">
                    {!! $kartaQr->kodSvg($adresQr) !!}
                </div>
                <p class="druk-pomocnik-qr-adres m-0">{{ $adresQr }}</p>
            </section>
        @endif

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
        @guest
            <p>
                <x-zglos-dla-goscia typ="recipe" :id="$recipe->slug" :pelny="true" />
            </p>
        @endguest

        {{--
            „MOJA WERSJA" (issue #23, D-301).

            Przycisk POD przepisem, nie w pasku akcji obok „Ugotowałem":
            „Ugotowałem" jest najważniejszym sygnałem w produkcie (AGENTS.md §1)
            i nic nie ma z nim konkurować o pierwsze miejsce. Najpierw się
            gotuje, potem — jeśli wyszło po swojemu — zapisuje własną wersję.
            Policy (`fork`) pyta o wszystko naraz: cudzy, publiczny,
            opublikowany, bez blokady, konto aktywne. Bez uprawnienia nie ma
            przycisku, więc nie ma przycisku prowadzącego w 403.
        --}}
        @can('fork', $recipe)
            <section class="sekcja-strony kolumna-czytania" aria-labelledby="moja-wersja">
                <h2 id="moja-wersja">Gotujesz to po swojemu?</h2>
                <p>Zrób swoją wersję tego przepisu. Dostaniesz kopię do zmiany, widoczną tylko dla Ciebie. Po publikacji nad tytułem zostanie podpis z tym przepisem i jego autorem.</p>
                <form method="POST" action="{{ route('recipes.fork', $recipe->slug) }}">
                    @csrf
                    <button class="btn btn-secondary" type="submit">Zrób swoją wersję</button>
                </form>
            </section>
        @endcan

        {{-- WSKAZÓWKI OD GOTUJĄCYCH (#2352, D-333): tylko przyjęte, nic przy braku. --}}
        <x-wskazowki-przepisu :wskazowki="$wskazowki" :najnowszaWersja="$najnowszaWersja" />

        {{--
            WERSJE INNYCH OSÓB — wyróżnienie autora oryginału, nie ranking.
            Bez liczby wszystkich wersji (AGENTS.md §12), chronologicznie,
            tylko to, co widz może zobaczyć (`MojaWersja::wersjeDlaWidza()`).
            Pusta lista nie rysuje nagłówka: „Nikt jeszcze nie zrobił swojej
            wersji" pod cudzym przepisem brzmiałoby jak zarzut.
        --}}
        @if($wersje !== null && $wersje->isNotEmpty())
            <section class="stack" aria-labelledby="wersje-innych">
                <h2 id="wersje-innych" class="m-0">Wersje innych osób</h2>
                <p class="meta m-0">Ten przepis zainspirował inne osoby. Każda wersja jest podpisana tym przepisem.</p>
                <div class="stack" id="lista-wersji">
                    @foreach($wersje as $wersja)
                        <x-recipe-card :recipe="$wersja" />
                    @endforeach
                </div>
                <x-show-more :paginator="$wersje" czego="wersji" lista="lista-wersji" />
            </section>
        @endif

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
                <div class="stack" id="lista-wykonan">
                    @foreach($cookedEvents as $event)
                        <x-cooked-card :event="$event" />
                    @endforeach
                </div>
                <x-show-more :paginator="$cookedEvents" czego="wykonań" lista="lista-wykonan" />
            @else
                {{-- Pusty wynik dotyczy tego widza: blokady mogą ukryć wszystkie
                     wykonania, więc tekst nie ocenia, czy ktoś już gotował. --}}
                @guest
                    <x-empty-state title="Nie ma tu widocznych wykonań" action="Załóż konto, żeby dodać wykonanie" :href="route('register')">
                        <span>Po ugotowaniu możesz dodać zdjęcie i kilka słów.</span>
                    </x-empty-state>
                    <p class="meta">Masz już konto? <a href="{{ route('login') }}">Zaloguj się</a>.</p>
                @else
                    @can('cook', $recipe)
                        <x-empty-state title="Nie ma tu widocznych wykonań" action="Dodaj swoje wykonanie" :href="route('cooked.create', $recipe->slug)">
                            <span>Po ugotowaniu możesz dodać zdjęcie i kilka słów.</span>
                        </x-empty-state>
                    @else
                        <x-empty-state title="Nie ma tu widocznych wykonań">
                            <span>Tutaj pojawią się wykonania dostępne dla Ciebie.</span>
                        </x-empty-state>
                    @endcan
                @endguest
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
            <x-zdejmij-z-urzedu :tresc="$recipe" typ="recipe" />

            <x-comment-thread :comments="$komentarze" :ile="$komentarzyRazem" :action="route('recipes.comment', $recipe->slug)" :autor-przepisu="$recipe->author_id" :tekst-startowy="$tekstStartowyPytania" />
        </div>
    </article>
</x-layout>
