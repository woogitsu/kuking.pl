{{--
    Szkielet każdej strony Kuking.

    Rzeczy, które MUSZĄ tu zostać:
    - `data-text-scale` z konta użytkownika — ustawienie rozmiaru tekstu
      przetrwa zmianę przeglądarki (docs/UX_50_PLUS.md);
    - `data-theme="dark"` — WYŁĄCZNIE stąd bierze się ciemny motyw, arkusz
      stylów już nie ogląda się na `prefers-color-scheme` (docs/DECISIONS.md,
      D-019). Jasny jest domyślny, gdy atrybutu nie ma;
    - link „Przejdź do treści” dla klawiatury;
    - komunikaty w `aria-live`, żeby czytnik ekranu ogłosił „Szkic zapisany”;
    - podpis tekstowy pod każdą ikoną w nawigacji.
--}}
@props([
    'title' => null,
    'description' => null,
    'noindex' => false,
    // Livewire dociągamy TYLKO na stronach, które go naprawdę używają
    // (dziś: kreator przepisu). Reszta serwisu działa bez tego skryptu
    // i nie ma powodu, żeby go pobierała — AGENTS.md → JavaScript jest
    // ulepszeniem, nie warunkiem.
    'livewire' => false,
    // Strona powitalna dostaje SZERSZY układ niż reszta widoków gościa.
    //
    // Reszta gościa (`/odkryj`, logowanie, rejestracja) to ekrany do CZYTANIA
    // i zostaje przy jednej kolumnie 720 px — długość linii jest tam ważniejsza
    // niż zapełnienie ekranu. Strona powitalna czytania prawie nie ma: to
    // nagłówek, dwie karty i siatka zdjęć. Przy jednej kolumnie zostawiała
    // po bokach pustkę na połowie ekranu (zgłoszenie właściciela z 7 września).
    'powitalny' => false,
    // Zdjęcie do karty w mediach społecznościowych (issue #14). Przekazujemy
    // model Media, a nie gotowy adres — komponent sam wybiera wariant i zna
    // wymiary, których Facebook i WhatsApp wymagają, żeby nie przycinać
    // obrazka na ślepo.
    'image' => null,
    'ogType' => 'website',
    /*
     * `szynaWTresci` — TEN EKRAN UŻYWA KOLUMNY SZYNY OD ŚRODKA (issue #365).
     *
     * Zwykły ekran z szyną podaje `<x-slot:rail>` i dostaje osobny
     * `<aside class="app-rail">` ZA `<main>`. Strona przepisu nie może tego
     * zrobić: jej blok „Ugotowałem / Zapisuję / Gotuję" musi stać w kodzie
     * PRZED składnikami, bo na telefonie kolumn nie ma i to kolejność w kodzie
     * decyduje, co człowiek czyta najpierw. Slot renderuje się za całym
     * `<main>`, czyli za krokami i komentarzami — a tam ta akcja już raz
     * leżała i została stamtąd wyciągnięta (`EkranPrzepisuWedlugKituTest`).
     *
     * Dlatego ten ekran zostawia blok w `<main>`, a `<main>` dostaje OBIE
     * kolumny: czytania i szyny. Rozkłada je siatka samego ekranu
     * (`.przepis-uklad` w app.css). Layout robi tu dwie rzeczy i tylko te:
     * dokłada klasę `app-body-tresc-z-szyna` na ramę i — u gościa — liczy tę
     * ramę, belkę oraz stopkę z szerszego tokenu, tak samo jak dla ekranu
     * z prawdziwą szyną (D-122). Bez tego gość ogląda przepis na stronie
     * zwiniętej do 768 px przy monitorze 1920 px.
     */
    'szynaWTresci' => false,
])

@php
    $user = auth()->user();
    $scale = $user?->text_scale ?? 100;
    // Jasny/ciemny wygląd (docs/DECISIONS.md, D-019). Zalogowany ma wybór
    // na koncie; gość — w ciasteczku (ThemeController). Brak jednego
    // i drugiego znaczy jasny, bo to jest teraz DOMYŚLNY motyw serwisu,
    // niezależnie od tego, co ustawił system operacyjny odwiedzającego.
    $theme = $user?->theme ?? request()->cookie(config('kuking.theme.cookie'));
    $theme = $theme === 'dark' ? 'dark' : 'light';
    $unread = $user?->unreadNotificationsCount() ?? 0;

    // Pozycja „Dodaj" (pasek dolny i nawigacja boczna, UI kit v2 etap D)
    // zostaje bieżącą pozycją przez CAŁY proces dodawania, nie tylko na
    // ekranie wyboru. `/dodaj` to pierwszy krok; wybór zdjęcia i kreator
    // przepisu (obie odmiany) to jego dalszy ciąg pod własnymi trasami —
    // dokładnie ten sam kształt co „Moje" (`collections.*`), które od dawna
    // dopasowuje się przez wzorzec. Bez tego menu przestawało pokazywać,
    // gdzie jest użytkownik, w chwili gdy naprawdę coś dodawał.
    $naDodaj = request()->routeIs(['add', 'posts.create', 'recipes.create*']);
    $pageTitle = $title ? $title.' — Kuking' : 'Kuking — pokaż, co dziś ugotowałeś';

    // ------------------------------------------------------------------
    //  TRYB PANELU — MENU BEZ RZECZY UŻYTKOWNIKA (prośba właściciela:
    //  „dać oddzielny przycisk, który pokaże tylko menu admina, bez
    //  przycisków typowych dla użytkownika (moje, profil itp.)").
    //
    //  TRYB WYNIKA ZE ŚCIEŻKI, NIE Z PRZEŁĄCZNIKA. Jesteś na `/admin/**`
    //  — widzisz menu panelu; wychodzisz z `/admin/**` — widzisz menu
    //  serwisu. Nie ma tu ani skryptu, ani stanu w sesji, i to jest wybór,
    //  nie lenistwo:
    //
    //    * bez JavaScriptu (AGENTS.md §5) — przełącznik trzymany w JS
    //      przestaje istnieć dokładnie wtedy, gdy skrypt się nie dociągnie;
    //    * stan zapamiętany w sesji potrafi się ZACIĄĆ: moderator, który raz
    //      „wszedł w tryb panelu", wracał na `/home` i dalej widziałby menu
    //      panelu, bo tak stoi w sesji. Wtedy potrzebny jest drugi mechanizm
    //      na odzyskanie normalnego menu — a on też może się zaciąć;
    //    * ten sam adres pokazuje tym samym oczom to samo, więc opis
    //      „kliknij Zgłoszenia w menu" jest prawdziwy dla każdego. Adres
    //      wklejony z powiadomienia trafia od razu w tryb panelu.
    //
    //  KOSZT, KTÓRY PRZYJMUJEMY ŚWIADOMIE: „Powiadomienia" i „Ustawienia"
    //  nie są ekranami `/admin/**`, więc kliknięcie ich wychodzi z trybu
    //  panelu. Powrót to jedno kliknięcie („Otwórz panel moderacji" stoi
    //  w menu serwisu), a alternatywą byłby stan w sesji z jego zacięciami.
    //
    //  `isModerator()` w warunku jest istotne dla BEZPIECZEŃSTWA: bez niego
    //  wystarczyłoby, żeby ktokolwiek trafił na trasę `admin.*`, by dostać
    //  inne menu. Zwykły użytkownik i gość dostają z `/admin/**` 404
    //  (`EnsureUserIsModerator`) i w ich HTML-u nie ma ani jednego śladu
    //  panelu — pilnuje tego `TrybPaneluWMenuTest`.
    $wTrybiePanelu = $user?->isModerator() === true && request()->routeIs('admin.*');

    // ------------------------------------------------------------------
    //  LICZNIKI PRZY POZYCJACH PANELU (zgłoszenie właściciela z 10 września:
    //  „w »Odwołania« nie ma takiego kwadracika jak przy Powiadomieniach").
    //
    //  JEDEN ODCZYT Z CACHE NA CAŁE MENU, ZERO `COUNT(*)`. Menu panelu stoi
    //  na KAŻDEJ stronie `/admin/**`, a kolejek jest pięć — pięć zapytań
    //  liczących w tym bloku byłoby pięcioma zapytaniami na każdą odsłonę,
    //  najdroższymi dokładnie wtedy, gdy kolejki są pełne. Przeliczanie
    //  schodzi więc poza ścieżkę żądania, dokładnie jak przy liczniku
    //  społeczności w stopce: pełne uzasadnienie i pomiar w
    //  `App\Domain\Moderation\KolejkiPanelu`, a niezależność liczby zapytań
    //  od zawartości kolejek pilnuje `LicznikiKolejekBezZapytanTest`.
    //
    //  Liczby czytamy tylko dla moderatora — zwykły użytkownik nie ma w menu
    //  ani jednej pozycji panelu, więc nie ma po co sięgać nawet do cache.
    $kolejki = $user?->isModerator() === true
        ? app(\App\Domain\Moderation\KolejkiPanelu::class)->liczby()
        : [];

    // ------------------------------------------------------------------
    //  KARTA DO WYSŁANIA RODZINIE (issue #14)
    //
    //  Link do przepisu wklejony w Messengera albo WhatsAppa pokazywał
    //  do tej pory sam tytuł, bez zdjęcia. W serwisie o gotowaniu to jest
    //  strata najważniejszej rzeczy: nikt nie klika w link do jedzenia,
    //  którego nie widać.
    //
    //  Adres MUSI być bezwzględny. Scrapery Facebooka i WhatsAppa nie mają
    //  kontekstu strony, więc `/storage/media/...` jest dla nich niczym —
    //  cicho pomijają taki obrazek i karta wraca do postaci bez zdjęcia.
    //  Dysk lokalny zwraca ścieżkę względną, R2 zwraca pełny adres, więc
    //  sprawdzamy, co dostaliśmy, zamiast zakładać.
    //
    //  AUDYT A3 — DLACZEGO JEST TU `isReady()`, A NIE SAMO `$image?->url()`
    //  `Media::url()` ma WŁASNY fallback na `kuking-mark.svg`, kiedy zdjęcie
    //  nie ma jeszcze wygenerowanych wariantów (patrz komentarz w
    //  app/Models/Media.php: „Pusta ramka jest gorsza niż nic"). Słuszne
    //  wewnątrz strony — ale świeży wpis ma `Media` w stanie `pending` przez
    //  kilka sekund, zanim zadanie w tle skończy przetwarzanie, więc bez tego
    //  warunku `og:image` regularnie wskazywał na TEN WŁAŚNIE SVG. Facebook,
    //  WhatsApp i Messenger nie renderują SVG w podglądzie linku — i
    //  zapamiętują PIERWSZY pobrany podgląd na zawsze, więc link wysłany
    //  zaraz po publikacji zostawał bez zdjęcia na stałe, nawet gdy
    //  zdjęcie dawno było gotowe (KartaDoUdostepnianiaTest,
    //  test_wpis_ze_zdjeciem_niegotowym_nie_podaje_svg_w_karcie).
    //
    //  CZEGO NIE WYBRANO: pokazywania prawdziwego zdjęcia „na wyrost", zanim
    //  jest `ready`. Egzemplarz przed przetworzeniem bywa z nietkniętym
    //  EXIF-em i współrzędnymi GPS kuchni autora (AGENTS.md §7) — podanie go
    //  scraperowi byłoby dokładnie tym wyciekiem, przed którym ten sam
    //  paragraf ostrzega. Zapasowa karta, którą serwis społecznościowy
    //  odświeży, gdy ktoś wklei link ponownie już po tym, jak zdjęcie będzie
    //  gotowe, jest bezpieczniejsza niż karta, która nigdy się nie odświeży.
    $ogImage = ($image !== null && $image->isReady()) ? $image->url('large') : null;

    if ($ogImage !== null && ! str_starts_with($ogImage, 'http')) {
        $ogImage = url($ogImage);
    }

    // Zapasowa karta dla stron bez zdjęcia (i dla zdjęcia jeszcze
    // niegotowego — patrz wyżej). SVG tu NIE ZADZIAŁA: Facebook, WhatsApp
    // i Signal go nie renderują i pokazują pustą ramkę. Stąd PNG
    // w formacie 1200×630, czyli tym, którego wszyscy oczekują.
    $ogImage ??= asset('icons/kuking-udostepnianie.png');

    // Wymiary MUSZĄ opisywać PLIK, na który wskazuje `$ogImage` powyżej —
    // inaczej scraper dostaje rozmiar prawdziwego (jeszcze niegotowego)
    // zdjęcia doklejony do zapasowego logo 1200×630 i przycina kartę źle.
    // Stąd ten sam warunek `isReady()`, nie sam fakt, że `$image` istnieje.
    $ogImageGotowe = $image !== null && $image->isReady();

    // ------------------------------------------------------------------
    //  CZY TEN EKRAN MA PRAWĄ SZYNĘ (D-122)
    //
    //  Zgłoszenie właściciela: „niektóre podstrony jak napisz do nas jest
    //  bardzo wąskie, gdzie po prawej i lewej można coś dodać na kompie".
    //  Arkusz zwijał układ gościa do JEDNEJ kolumny 768 px na każdej
    //  szerokości, a uzasadniał to zdaniem „gość nie ma nawigacji bocznej
    //  ani szyny" — druga połowa była nieprawdą od 7 września 2026. Slot
    //  `rail` podają trzy publiczne widoki: `/szukaj`, `/napisz-do-nas`
    //  i `/@nazwa`. Skutek: osoba, która pisze „nie mogę się zalogować"
    //  z komputera, czytała blok „Nie możesz się zalogować" POD CAŁYM
    //  formularzem, na stronie wąskiej na 768 px przy monitorze 1920 px.
    //
    //  LICZY SIĘ TREŚĆ SLOTU, NIE SAM SLOT. `<x-slot:rail>` bywa podany
    //  i pusty: `x-szyna-profilu` na CUDZYM profilu oglądanym przez gościa
    //  nie wypisuje ani jednego bloku (blok z liczbami jest pod `@auth`,
    //  a tagi i zeszyty mogą nie istnieć). Sam `isset($rail)` dałby wtedy
    //  gościowi drugą kolumnę szeroką na 352 px, w której nic nie stoi —
    //  a pusta kolumna wygląda na usterkę układu, nie na wybór.
    //
    //  TA ZMIENNA WYBIERA WYŁĄCZNIE KLASĘ UKŁADU. Samo `<aside class=
    //  "app-rail">` renderuje się dalej pod `@isset($rail)`, czyli tak jak
    //  przed tą zmianą: pusty slot daje pusty `<aside>` pod treścią, który
    //  nic nie zajmuje i niczego nie przesuwa. Zwężenie tego warunku do
    //  `$maSzyne` byłoby osobną zmianą (znikłby też pusty `<aside>` na
    //  ekranach zalogowanego, np. `/zeszyt` bez ostatnio zapisanych) —
    //  i jest tu świadomie NIEZROBIONE, bo dotyczy ekranów, których
    //  zgłoszenie właściciela nie obejmowało.
    //
    //  Komentarze HTML wycinamy jak `ComponentSlot::hasActualContent()`
    //  w Laravelu — slot złożony z samego komentarza jest pusty.
    $maSzyne = isset($rail)
        && trim((string) preg_replace('/<!--.*?-->/s', '', (string) $rail)) !== '';

    //  SZEROKA RAMA GOŚCIA: ZA SZYNĘ LICZY SIĘ TAKŻE SZYNA OD ŚRODKA (#365).
    //
    //  Belka, stopka i rama biorą u gościa szerszy token wtedy, gdy obok
    //  treści NAPRAWDĘ coś stoi — wszystko jedno, czy jest to `<aside>` ze
    //  slotu `rail`, czy kolumna szyny zajęta przez sam ekran
    //  (`szynaWTresci`, dziś: strona przepisu). Dwie liczby na jedną krawędź
    //  to rozjazd, którego potem nikt nie umie wytłumaczyć — stąd JEDEN
    //  warunek na trzy warstwy.
    //
    //  Rama SIATKI dostaje osobną klasę, bo to dwa różne układy:
    //  `app-body-solo-z-szyna` robi drugą kolumnę dla `<aside>`,
    //  a `app-body-tresc-z-szyna` oddaje obie kolumny `<main>`.
    $szerokaRama = $maSzyne || $szynaWTresci;
@endphp

<!DOCTYPE html>
<html lang="pl" @if($scale !== 100) data-text-scale="{{ $scale }}" @endif @if($theme === 'dark') data-theme="dark" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>

    @if($description)
        <meta name="description" content="{{ $description }}">
    @endif

    @if($noindex)
        <meta name="robots" content="noindex, nofollow">
    @endif

    <meta property="og:site_name" content="Kuking">
    <meta property="og:title" content="{{ $pageTitle }}">
    @if($description)
        <meta property="og:description" content="{{ $description }}">
    @endif
    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:locale" content="pl_PL">
    {{-- Adres kanoniczny bez parametrów zapytania: inaczej ten sam przepis
         wysłany z „?zakladka=..." liczy się jako osobna strona i zbiera
         własne polubienia zamiast dołożyć do wspólnej puli. --}}
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:alt" content="{{ $title ?? 'Kuking' }}">
    {{-- `$ogImageGotowe`, nie sam `$image` (audyt A3): dla zdjęcia jeszcze
         niegotowego `og:image` powyżej i tak pokazuje zapasowe PNG
         1200×630, więc wymiary PRAWDZIWEGO (jeszcze nieopublikowanego)
         zdjęcia byłyby tu kłamstwem naklejonym na cudzy plik. --}}
    @if($ogImageGotowe && $image->width('large') && $image->height('large'))
        {{-- Wymiary podane wprost pozwalają pokazać kartę, ZANIM obrazek się
             pobierze. Bez nich Messenger rezerwuje miejsce dopiero po
             pobraniu i link przez chwilę wygląda na pusty. --}}
        <meta property="og:image:width" content="{{ $image->width('large') }}">
        <meta property="og:image:height" content="{{ $image->height('large') }}">
    @endif

    {{-- Duża karta tylko wtedy, gdy naprawdę jest gotowe zdjęcie. Przy
         zapasowym logo duży format to wielka plama koloru z małym znaczkiem —
         a niegotowe zdjęcie dostaje dokładnie tę zapasową kartę (patrz wyżej). --}}
    <meta name="twitter:card" content="{{ $ogImageGotowe ? 'summary_large_image' : 'summary' }}">

    <link rel="canonical" href="{{ url()->current() }}">
    <meta name="theme-color" content="#151714">

    <link rel="icon" href="{{ asset('icons/kuking-mark.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('icons/kuking-icon-192.png') }}">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if($livewire)
        @livewireStyles
    @endif

    {{--
        Analityka odwiedzin: Cloudflare Web Analytics (D-092) — „skąd ludzie
        przychodzą i które strony oglądają". Odpowiedź na prośbę właściciela
        o Google Analytics, dana BEZ cofania obietnicy z polityki
        prywatności: beacon nie stawia ciasteczek i nie zapisuje niczego na
        urządzeniu (zmierzone przez odczytanie pliku — D-092), więc baner
        zgody dalej nie jest do niczego potrzebny.

        BEZ `CLOUDFLARE_ANALYTICS_TOKEN` NIE MA TU ANI ŚLADU ZNACZNIKA.
        Lokalnie, w testach i w CI ta zmienna jest pusta — i wtedy
        `AnalitykaCloudflare::wlaczona()` oddaje `false`, a w HTML-u nie
        zostaje nawet komentarz. Ten sam wzorzec co przy Turnstile (D-050):
        konfiguracja opisuje stan środowiska, a nie zamiar.

        `defer`, nie `async`: to jest rzecz NAJMNIEJ ważna na tej stronie.
        Analityka ma się doładować po treści, a nie konkurować z nią o łącze —
        AGENTS.md mówi wprost, że JavaScript jest ulepszeniem, nie warunkiem,
        i tutaj kosztem jego braku jest wyłącznie nasza własna niewiedza, nie
        funkcja dla człowieka.

        BEZ `nonce` — I TO NIE JEST NIEDOPATRZENIE. Podpis dotyczy skryptów
        WPISANYCH w stronę; ten jest pobierany z obcego hosta, który
        `ApplySecurityHeaders` dopuszcza z nazwy w `script-src`, tak samo jak
        skrypt Turnstile obok. Dorzucony `nonce` niczego by tu nie zmienił.

        Adres i token idą przez `App\Support\AnalitykaCloudflare`, czyli
        przez `config/kuking.php`, a NIE przez `env()` w widoku: na produkcji
        konfiguracja jest zbuforowana i `env()` oddałoby wtedy `null`, czyli
        znacznik z pustym tokenem — skrypt, który się ładuje i nic nie liczy.
        Z tego samego powodu adres NIE jest tu wpisany literałem: ten sam host
        musi trafić do nagłówka CSP, a dwa literały w dwóch plikach to
        gwarancja, że jeden zostanie w tyle.

        Atrybut `data-cf-beacon` jest w apostrofach, bo jego wartość to JSON
        z cudzysłowami. Blade go zaescapuje na `&quot;` — przeglądarka
        rozwija encje w wartościach atrybutów, więc beacon widzi poprawny
        JSON, a my nie renderujemy niczego surowego.
    --}}
    @if(\App\Support\AnalitykaCloudflare::wlaczona())
        <script defer
                src="{{ \App\Support\AnalitykaCloudflare::adresSkryptu() }}"
                data-cf-beacon='{{ \App\Support\AnalitykaCloudflare::konfiguracjaBeacona() }}'></script>
    @endif

    {{ $head ?? '' }}
</head>
{{-- `uklad-solo` steruje szerokością belki i stopki dla gościa — musi iść
     w parze z `app-body-solo` na siatce niżej. Jedna klasa na <body>, bo
     belka i stopka stoją POZA `.app-body` i inaczej nie mają skąd wiedzieć,
     że ta strona nie ma nawigacji bocznej.

     `uklad-solo-z-szyna` DOCHODZI do `uklad-solo`, nie zastępuje jej (D-122):
     od 80rem belka i stopka biorą wtedy szerszy sufit, bo tyle ma treść
     z szyną obok. Poniżej 80rem szyna leci pod treścią i szerokość jest ta
     sama co bez niej — dlatego druga klasa nic tam nie robi. --}}
<body class="@guest {{ $powitalny ? 'uklad-powitalny' : 'uklad-solo'.($szerokaRama ? ' uklad-solo-z-szyna' : '') }} @endguest" data-marka="kuking-2026">
    <a class="skip-link" href="#tresc">Przejdź do treści</a>

    <header class="topbar marka-topbar">
        <div class="topbar-inner">
            <a class="wordmark" href="{{ $user ? route('home') : route('landing') }}">
                {{-- Znak wklejony wprost, nie przez <img> — inaczej nie
                     dziedziczy koloru i w trybie ciemnym zostaje czarny. --}}
                <x-kuking-mark :rozmiar="36" />
                {{-- Logotyp rozbity na dwa elementy jest dla czytnika ekranu
                     dwoma osobnymi napisami. Podajemy mu jeden, całą nazwę. --}}
                <span aria-hidden="true">Ku<span class="wordmark-king">King</span><span class="wordmark-tld">.pl</span></span>
                <span class="visually-hidden">Kuking — strona główna</span>
            </a>

            {{--
                Pasek akcji — klasa, nie styl wpisany w atrybucie (issue #80).

                Poprzednia wersja miała tu `display:flex` bez zawijania i dwa
                przyciski z pełnym tekstem. Przy oknie 360 px cała strona
                przewijała się w bok (`scrollWidth` 493), a „Dodaj" leżało
                całkowicie poza ekranem. Reguły układu muszą siedzieć w CSS,
                bo tylko tam da się je uzależnić od szerokości ekranu.
            --}}
            @auth
                {{--
                    SZUKAJ W BELCE (UI kit v2, ekran 01).

                    Zwykły formularz GET — działa bez JavaScriptu i bez niczego
                    poza przeglądarką. To jest ta sama trasa co pozycja „Szukaj"
                    w nawigacji, więc obie drogi prowadzą w to samo miejsce
                    i żadna nie jest jedyna.

                    Na telefonie pole znika (patrz `.topbar-szukaj` w app.css):
                    belka ma tam pomieścić logotyp i powiadomienia, a „Szukaj"
                    stoi w pasku dolnym, w zasięgu kciuka.
                --}}
                <form class="topbar-szukaj" method="GET" action="{{ route('search') }}" role="search">
                    <label class="visually-hidden" for="topbar-q">Szukaj przepisów, osób i składników</label>
                    <x-ikona nazwa="search" :rozmiar="22" class="topbar-szukaj-ikona" />
                    <input class="topbar-szukaj-pole" id="topbar-q" type="search" name="q"
                           value="{{ request()->routeIs('search') ? request('q') : '' }}"
                           placeholder="Szukaj przepisów, osób i składników…">
                </form>
            @endauth


            @auth
                @unless($wTrybiePanelu)
                    <nav class="marka-nawigacja" aria-label="Nawigacja główna — komputer">
                        <a href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>Start</a>
                        <a href="{{ route('search') }}" @if(request()->routeIs('search')) aria-current="page" @endif>Szukaj</a>
                        <a href="{{ route('add') }}" @if($naDodaj) aria-current="page" @endif>Dodaj</a>
                        <a href="{{ route('collections.index') }}" @if(request()->routeIs('collections.*')) aria-current="page" @endif>Moje</a>
                        <a href="{{ route('profile.show', $user->profile->username) }}" @if(request()->routeIs('profile.show')) aria-current="page" @endif>Profil</a>
                    </nav>
                @endunless
            @endauth

            <div class="topbar-actions">
                @auth
                    {{--
                        Powiadomienia zostają TEKSTEM, choć kit ma tu samą
                        ikonę dzwonka. „Ikona nigdy sama" jest twardą zasadą
                        tego produktu (AGENTS.md §5), a dzwonek bez podpisu
                        jest dla części naszych odbiorców po prostu nieczytelny.

                        `topbar-mobile-only` — POZYCJA ZNIKA Z BELKI TAM, GDZIE
                        WIDAĆ NAWIGACJĘ BOCZNĄ, I TYLKO TAM.

                        Od 64rem `.side-nav` niesie DOKŁADNIE tę samą pozycję:
                        ten sam napis, ten sam adres i ten sam licznik
                        nieprzeczytanych (`side-nav-dol` niżej w tym pliku).
                        Na desktopie „Powiadomienia" były więc na ekranie
                        dwa razy. Nic nie ubywa: poniżej 64rem, gdzie
                        `.side-nav` ma `display: none`, ten egzemplarz zostaje
                        jedynym i pokazuje się bez zmian.

                        DLACZEGO TO JEST KONIECZNE, A NIE KOSMETYCZNE
                        Od 80rem belka stoi w tej samej trzykolumnowej siatce
                        co treść, a jej trzecia kolumna ma zmierzone 352 px
                        i jest zapełniona co do piksela: „Powiadomienia" 176 +
                        „Dodaj" 95 + dawny awatar 40 + odstępy = 352. Menu
                        konta z widocznym napisem (issue #344) potrzebuje tam
                        138 px zamiast 40 — kolumna rosła kosztem kolumny
                        środkowej i pole „Szukaj" przestawało stać nad tekstem,
                        który przeszukuje (zmierzone: 739 zamiast 872 przy
                        1280 px, czyli 133 px za wąsko; `scripts/dostepnosc.mjs`,
                        „Wyrównanie belki do siatki treści"). Sprawdzone: nawet
                        sam awatar ze strzałką i BEZ napisu przekraczał tę
                        kolumnę o 19 px. Czegokolwiek się tam nie dołoży,
                        miejsce musi się wziąć z rzeczy, która stoi obok
                        drugi raz.
                    --}}
                    <a class="btn btn-quiet topbar-mobile-only marka-powiadomienia-link" href="{{ route('notifications.index') }}">
                        Powiadomienia
                        @if($unread > 0)
                            <span class="badge badge-cooked">{{ $unread }}</span>
                            <span class="visually-hidden">nieprzeczytanych</span>
                        @endif
                    </a>
                    {{--
                        „Dodaj" w pasku GÓRNYM pokazuje się dopiero tam, gdzie
                        znika pasek DOLNY (od 64rem, patrz app.css).

                        To nie jest ukrycie głównej akcji: na telefonie „Dodaj"
                        stoi w pasku dolnym — z ikoną ORAZ podpisem, w środku
                        ekranu, w zasięgu kciuka. Trzymanie go jednocześnie
                        w obu paskach oznaczało dwa pełnotekstowe przyciski
                        w belce, a to była bezpośrednia przyczyna przewijania
                        w bok z issue #80.

                        Ukrycie NAPISU przy ikonie byłoby złamaniem zasady
                        „ikona nigdy sama" (AGENTS.md §5) — dlatego usuwamy
                        pozycję z paska, a nie jej podpis.
                    --}}
                    <a class="btn btn-primary topbar-desktop-only" href="{{ route('add') }}">Dodaj</a>

                    {{--
                        MENU KONTA PRZY AWATARZE (issue #344).

                        CO BYŁO WCZEŚNIEJ I DLACZEGO TO NIE WYSTARCZAŁO
                        Zwykły odnośnik na własny profil, z klasą
                        `topbar-desktop-only` i podpisem schowanym w
                        `visually-hidden`. Komentarz w tym miejscu mówił, że
                        menu „bez skryptu nie otwiera się wcale" — i to była
                        pomyłka co do faktów: `<details>` otwiera się bez
                        jednej linijki JavaScriptu i ten sam wzorzec stoi
                        w tym repozytorium od dawna przy karcie wpisu
                        (`components/post-card.blade.php`). Idziemy dokładnie
                        tą samą drogą.

                        AWATAR PRZESTAJE BYĆ `topbar-desktop-only`
                        Na telefonie `.side-nav` ma `display: none`
                        (`resources/css/app.css:1173`), a pasek dolny niesie
                        pięć pozycji i szóstej mieć nie może (AGENTS.md §5).
                        Bez tego menu jedyną drogą do Ustawień i do wylogowania
                        był własny profil — czyli trzeba było wiedzieć, że
                        obsługa konta stoi pod cudzą nazwą (D-168). Teraz
                        wejście jest tam, gdzie człowiek go szuka: przy swoim
                        zdjęciu, na każdym ekranie.

                        IKONA NIGDY NIE JEST SAMA (AGENTS.md §5)
                        W `<summary>` stoi awatar, WIDOCZNY napis „Moje konto"
                        i strzałka. Sam awatar — nawet z podpisem dla czytnika
                        ekranu — byłby obrazkiem, po którym nie widać, że coś
                        się pod nim kryje. `aria-label` powtarza widoczny napis
                        i dokłada rolę (WCAG 2.5.3: nazwa dostępna musi
                        zawierać to, co widać).

                        BEZ HOVERA I BEZ SKRYPTU
                        Menu otwiera kliknięcie albo dotknięcie, nigdy
                        najechanie myszą: przy mniej pewnej ręce hover zamyka
                        menu w trakcie celowania, a na dotyku nie istnieje
                        w ogóle. Zamykanie klawiszem Esc i kliknięciem obok
                        dokłada `resources/js/app.js` — jako DODATEK. Bez
                        skryptu menu nadal otwiera się i zamyka tym samym
                        przyciskiem, więc nie ma tu martwego przycisku (D-053).

                        WYLOGOWANIE ZOSTAJE POST-em Z TOKENEM CSRF
                        Ten sam składnik co w nawigacji bocznej i na własnym
                        profilu (`components/wyloguj.blade.php`). Odnośnik GET
                        wylogowywałby człowieka z podglądu linku albo
                        z prefetchu przeglądarki — także wewnątrz menu.
                    --}}
                    <details class="topbar-konto">
                        {{-- NAPIS BRZMI „Konto", A NIE „Moje konto" — i to jest
                             pomiar, nie skrót myślowy. Na telefonie 390 px
                             w belce zostaje 358 px na logotyp (159),
                             „Powiadomienia" (176) i ten przycisk. „Moje konto"
                             ze strzałką ma 194 px i zrzuca przycisk do
                             TRZECIEGO wiersza belki, spychając kafelek
                             dodawania — główną akcję serwisu — o 59 px niżej.
                             „Konto" ma 138 px i mieści się obok „Powiadomień"
                             w jednym wierszu. Przy własnym zdjęciu i strzałce
                             jedno słowo mówi to samo. --}}
                        <summary class="topbar-konto-przycisk" aria-label="Konto — menu: profil, ustawienia, wylogowanie">
                            <x-avatar :user="$user" :size="40" />
                            <span class="topbar-konto-napis">Konto</span>
                            <x-ikona nazwa="chevron" :rozmiar="20" class="topbar-konto-strzalka" />
                        </summary>
                        {{-- Lista, nie zbiór `<div>`-ów: czytnik ekranu zapowiada
                             „3 pozycje", więc człowiek wie, ile ich jest, zanim
                             zacznie je przechodzić. --}}
                        <ul class="topbar-konto-tresc">
                            <li><a href="{{ route('profile.show', $user->profile->username) }}">Mój profil</a></li>
                            <li><a href="{{ route('settings.index') }}">Ustawienia</a></li>
                            @if($user->isModerator())
                                <li><a href="{{ route('admin.reports') }}">Otwórz panel moderacji</a></li>
                            @endif
                            <li><x-wyloguj class="topbar-konto-wyjscie" formClass="topbar-konto-wyjscie-formularz">Wyloguj się</x-wyloguj></li>
                        </ul>
                    </details>
                @else
                    <a class="btn btn-quiet" href="{{ route('login') }}">Zaloguj się</a>
                    <a class="btn btn-primary" href="{{ route('register') }}">Załóż konto</a>
                @endauth
            </div>
        </div>
    </header>

    <div class="app-shell">
        {{-- `app-body-solo` MUSI iść w parze z brakiem <nav class="side-nav">
             niżej: siatka na desktopie rezerwuje pierwszą kolumnę na
             nawigację, więc bez niej treść wpadłaby w kolumnę szeroką na
             15rem. Pilnuje tego test UkladGosciaTest.

             `data-tryb-panelu` — TEN SAM atrybut co na `<nav class="side-nav">`
             niżej, jeden znacznik stanu czytany przez dwa selektory w CSS.
             Panel moderacji nie ma slotu `rail` i nigdy go mieć nie będzie,
             więc od 80rem nie rezerwujemy dla niego pustej trzeciej kolumny
             (issue #294, punkt 2 — patrz uzasadnienie w app.css).

             `app-body-solo-z-szyna` DOCHODZI do `app-body-solo` na ekranie
             gościa, który naprawdę ma czym wypełnić szynę (D-122). Obie klasy
             stoją razem, bo `app-body-solo` znaczy „układ gościa, bez
             nawigacji bocznej" i czyta to także `ekran-profilu.css`.

             `app-body-tresc-z-szyna` idzie POZA `@guest`, bo dotyczy obu:
             zalogowanemu oddaje trzecią kolumnę (dotąd pustą), gościowi —
             drugą. Ekran, który ją podaje, nie ma `<aside class="app-rail">`
             i mieć nie będzie; kolumnę szyny zajmuje jego własna siatka
             (`szynaWTresci` wyżej, issue #365). --}}
        <div class="app-body marka-rama @if($maSzyne) marka-rama-z-szyna @endif @guest {{ $powitalny ? 'app-body-powitalny' : 'app-body-solo'.($maSzyne ? ' app-body-solo-z-szyna' : '') }} @endguest @if($szynaWTresci) app-body-tresc-z-szyna @endif" @if($wTrybiePanelu) data-tryb-panelu @endif>
            @auth
                {{--
                    NAWIGACJA BOCZNA WEDŁUG KITU (ekran 01).

                    Pięć pozycji u góry, a Powiadomienia i Ustawienia ODDZIELNIE
                    na dole kolumny. To nie jest kosmetyka: pierwsza piątka to
                    rzeczy, po które człowiek przychodzi (czytać, szukać, dodać,
                    wrócić do swojego), a dół to obsługa konta. Trzymanie
                    wszystkiego w jednym ciągu siedmiu pozycji kazało czytać
                    całą listę, żeby znaleźć „Dodaj".

                    „Świeżo z Kuking" wypada z tej listy, bo w kicie jest
                    zakładką feedu („Obserwowani / Świeżo z Kuking") — czyli stoi
                    tam, gdzie się go używa, a nie w osobnym menu.
                --}}
                {{--
                    TRYB SIEDZI W ATRYBUCIE `data-`, NIE W DODATKOWEJ KLASIE.

                    `class="side-nav"` musi zostać DOSŁOWNIE takie, jakie było:
                    testy nawigacji (`NawigacjaAktywnaPozycjaTest`,
                    `PanelModeracjiWMenuTest`, ten plik) wycinają menu ze strony
                    po tekście `<nav class="side-nav"` — druga klasa w tym
                    atrybucie wywraca je wszystkie i to jest jedyne, co po sobie
                    zostawia (zmierzone: trzy testy „Brak nawigacji bocznej").

                    Stan opisany atrybutem to zresztą wzorzec, który w tym
                    arkuszu już jest: `data-theme`, `data-text-scale`,
                    `.side-nav-item[aria-current="page"]`. Klasa mówi, CZYM
                    element jest; atrybut — w jakim jest stanie.
                --}}
                <nav class="side-nav" @if($wTrybiePanelu) data-tryb-panelu @endif aria-label="{{ $wTrybiePanelu ? 'Nawigacja panelu moderacji' : 'Nawigacja główna' }}">
                    {{--
                        W TRYBIE PANELU TEJ PIĄTKI NIE MA (prośba właściciela:
                        „menu admina bez przycisków typowych dla użytkownika —
                        moje, profil itp."). Nie jest to ukrycie na niby: te
                        pozycje NIE trafiają do HTML-a, więc nie da się do nich
                        dojść tabulatorem ani czytnikiem ekranu, a menu panelu
                        ma tyle pozycji, ile naprawdę widać.

                        Ekrany serwisu są stąd o jedno kliknięcie: „Wróć do
                        Kuking" niżej prowadzi na Start, czyli tam, gdzie ta
                        piątka znowu stoi w komplecie.
                    --}}
                    @if($wTrybiePanelu)
                        {{--
                            WYJŚCIE Z TRYBU — PIERWSZA POZYCJA MENU, nie ostatnia.

                            Stoi nad narzędziami, bo to jedyna droga z powrotem
                            do serwisu i musi być widoczna bez przewijania —
                            także na telefonie, gdzie to menu renderuje się nad
                            treścią ekranu. „Wróć do Kuking", nie „Wyjdź":
                            mówimy, DOKĄD to prowadzi, a nie czego się pozbywamy.

                            Ikona „home" jest tu prawdziwa, nie ozdobna — ten
                            odnośnik prowadzi dokładnie tam, gdzie pozycja
                            „Start". Podpis obok, jak wszędzie (AGENTS.md §5).

                            Bez `aria-current`: to nie jest bieżący ekran.
                        --}}
                        <a class="side-nav-item side-nav-powrot" href="{{ route('home') }}">
                            <x-ikona nazwa="home" /> Wróć do Kuking
                        </a>
                    @else
                        <ul class="stack-tight list-none p-0 m-0">
                            <li><a class="side-nav-item" href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif><x-ikona nazwa="home" /> Start</a></li>
                            <li><a class="side-nav-item" href="{{ route('search') }}" @if(request()->routeIs('search')) aria-current="page" @endif><x-ikona nazwa="search" /> Szukaj</a></li>
                            <li><a class="side-nav-item" href="{{ route('add') }}" @if($naDodaj) aria-current="page" @endif><x-ikona nazwa="plus" /> Dodaj</a></li>
                            <li><a class="side-nav-item" href="{{ route('collections.index') }}" @if(request()->routeIs('collections.*')) aria-current="page" @endif><x-ikona nazwa="book" /> Moje</a></li>
                            <li><a class="side-nav-item" href="{{ route('profile.show', $user->profile->username) }}" @if(request()->routeIs('profile.show')) aria-current="page" @endif><x-ikona nazwa="user" /> Profil</a></li>
                        </ul>
                    @endif

                    @if($user->isModerator())
                        {{--
                            SEKCJA „PANEL MODERACJI" — WYDZIELONA Z ZWYKŁEGO MENU
                            (zgłoszenie właściciela: „nie wiadomo, co jest normalną
                            podstroną, a co adminową").

                            Dotąd tych sześć pozycji stało w tym samym `<ul>`, tą
                            samą czcionką, bez nagłówka i bez KRESKI PRZED nimi —
                            kreska (`.side-nav-dol`) stała tylko PO nich, więc
                            moderator dostawał sygnał dopiero, gdy panel się już
                            skończył.

                            NAZWA „Panel moderacji", nie „Moderacja" ani „Panel
                            admina": to samo sformułowanie już żyje w kodzie
                            (`pages/admin/wymagane_2fa.blade.php`: „Ten panel
                            wymaga weryfikacji dwuetapowej", „Panel moderacji
                            pokazuje zgłoszenia, ukryte treści i odwołania…") —
                            dopisujemy się do istniejącego nazewnictwa zamiast
                            wprowadzać czwarte słowo na to samo miejsce. Ten sam
                            napis stoi też na pasku ekranów `/admin/**`, patrz
                            `components/panel-moderacji.blade.php`.

                            SEMANTYKA DLA CZYTNIKA EKRANU: prawdziwy `<h2>`
                            w `<nav>`, powiązany `aria-labelledby` z grupą
                            (`role="group"`) — to brzmi jako „Panel moderacji,
                            grupa" PRZED pierwszą pozycją, a nie jako dalszy ciąg
                            po „Profil".

                            WYRÓŻNIENIE JEST CELOWO STONOWANE: `--color-accent`
                            (oliwkowy/musztardowy — token już używany np. w
                            `.notice`), NIGDY `--color-danger`. To miejsce PRACY
                            moderatora, nie alarm.

                            `aria-current="page"` zostaje bez zmian na każdej
                            pozycji — `.side-nav-item[aria-current="page"]` ma
                            wyższą specyficzność niż kolor tej sekcji i nadpisuje
                            go tak samo jak dotąd.
                        --}}
                        {{-- Znacznik grupy zostaje BEZ ZMIAN. Wygląd w trybie
                             panelu (grupa jest wtedy jedyną rzeczą w menu, więc
                             kreska „oddzielam się od tego, co wyżej" nie ma czego
                             oddzielać) bierze się z `[data-tryb-panelu]` na
                             `<nav>` wyżej — patrz app.css. --}}
                        {{--
                            POZA PANELEM MENU POKAZUJE JEDNO WEJŚCIE, NIE DZIEWIĘĆ
                            POZYCJI (zgłoszenie właściciela: „po co w menu cały
                            panel moderacji i pod spodem przycisk »Otwórz panel
                            moderacji«?").

                            Miał rację: to była ta sama rzecz powiedziana dwa razy.
                            Dziewięć pozycji panelu stało w zwykłym menu obok
                            „Profil" i „Powiadomienia", a pod nimi przycisk, który
                            prowadził DOKŁADNIE tam, gdzie prowadziła pierwsza
                            z nich. Menu serwisu rosło o dziewięć wierszy pracy,
                            której się w tym miejscu nie wykonuje.

                            Teraz: poza panelem jedno wejście, w panelu pełna lista.
                            Kolejki wchodzi się przeglądać z panelu, nie z ekranu
                            własnego profilu.

                            LICZBA NIE ZNIKA — SUMUJE SIĘ. Plakietka przy wejściu
                            pokazuje, ile rzeczy czeka we WSZYSTKICH pięciu
                            kolejkach razem. Gdyby jej nie było, moderator
                            straciłby jedyny sygnał „jest robota", jaki miał poza
                            panelem, a to jest dokładnie ten rodzaj cichej straty,
                            którego AGENTS.md zabrania. Rozbicie na kolejki czeka
                            w panelu, jedno kliknięcie dalej.
                        --}}
                        @php
                            // Suma, nie `array_sum($kolejki)` — nazwy kolejek
                            // wypisane wprost, żeby nowy klucz w `KolejkiPanelu`
                            // (np. licznik czegoś, co nie jest kolejką do
                            // przejrzenia) nie doliczał się tu po cichu.
                            $czekaWPanelu = ($kolejki['bez_odpowiedzi'] ?? 0)
                                + ($kolejki['zgloszenia'] ?? 0)
                                + ($kolejki['sygnaly'] ?? 0)
                                + ($kolejki['odwolania'] ?? 0)
                                + ($kolejki['wiadomosci'] ?? 0);
                        @endphp

                        @if($wTrybiePanelu)
                        <div class="side-nav-moderacja" role="group" aria-labelledby="side-nav-moderacja-naglowek">
                            <h2 class="side-nav-moderacja-naglowek" id="side-nav-moderacja-naglowek">Panel moderacji</h2>
                            <ul class="side-nav-moderacja-lista stack-tight list-none p-0 m-0">
                                <li><a class="side-nav-item" href="{{ route('admin.unanswered') }}" @if(request()->routeIs('admin.unanswered')) aria-current="page" @endif><x-ikona nazwa="clock" /> Bez odpowiedzi <x-licznik-kolejki :ile="$kolejki['bez_odpowiedzi'] ?? 0" /></a></li>
                                <li><a class="side-nav-item" href="{{ route('admin.reports') }}" @if(request()->routeIs('admin.reports')) aria-current="page" @endif><x-ikona nazwa="shield" /> Zgłoszenia <x-licznik-kolejki :ile="$kolejki['zgloszenia'] ?? 0" /></a></li>
                                {{-- Odwołania dostają ikonę „chat", a nie wagę szalkową: odwołanie
                                     to pismo od człowieka, a nie wyrok. Zestaw ikon nie ma szalek
                                     i nie dokładam ich tutaj — nowy kształt to zmiana w komponencie
                                     ikon, która należy do prac nad UI kitem. --}}
                                {{-- Sygnały automatu (D-052) — OSOBNA pozycja, nie zakładka
                                     w Zgłoszeniach. Tam są sprawy od ludzi, z terminem
                                     odpowiedzi; tu maszynowe podejrzenia, których większość
                                     okaże się niczym. Ikona „filter", bo to jest sito, a nie
                                     tarcza: nic tu nikogo nie chroni, dopóki człowiek nie
                                     przeczyta. --}}
                                <li><a class="side-nav-item" href="{{ route('admin.sygnaly') }}" @if(request()->routeIs('admin.sygnaly')) aria-current="page" @endif><x-ikona nazwa="filter" /> Sygnały automatu <x-licznik-kolejki :ile="$kolejki['sygnaly'] ?? 0" /></a></li>
                                <li><a class="side-nav-item" href="{{ route('admin.appeals') }}" @if(request()->routeIs('admin.appeals')) aria-current="page" @endif><x-ikona nazwa="chat" /> Odwołania <x-licznik-kolejki :ile="$kolejki['odwolania'] ?? 0" /></a></li>
                                <li><a class="side-nav-item" href="{{ route('admin.daily-board') }}" @if(request()->routeIs('admin.daily-board')) aria-current="page" @endif><x-ikona nazwa="pin" /> Tablica na dziś</a></li>
                                {{-- Kolaż na stronie powitalnej — ten sam rodzaj wyboru
                                     redakcyjnego co tablica na dziś, ale ikona „image",
                                     bo tu wybiera się ZDJĘCIA, nie osoby i wpisy. --}}
                                <li><a class="side-nav-item" href="{{ route('admin.hero-kolaz') }}" @if(request()->routeIs('admin.hero-kolaz')) aria-current="page" @endif><x-ikona nazwa="image" /> Kolaż na powitanie</a></li>
                                {{-- Tagi promowane (D-021) — ten sam rodzaj wyboru redakcyjnego
                                     co tablica na dziś, stąd ta sama ikona. --}}
                                <li><a class="side-nav-item" href="{{ route('admin.tag-promotions') }}" @if(request()->routeIs('admin.tag-promotions')) aria-current="page" @endif><x-ikona nazwa="pin" /> Tagi promowane</a></li>
                                {{-- Wiadomości z „Napisz do nas" — ta sama ikona „chat"
                                     co odwołania, bo to też jest pismo od człowieka,
                                     a nie sprawa do rozstrzygnięcia. Osobna pozycja,
                                     nie zakładka w Zgłoszeniach: to jest inna kolejka
                                     i inna praca (patrz `WiadomosciController`). --}}
                                <li><a class="side-nav-item" href="{{ route('admin.contact') }}" @if(request()->routeIs('admin.contact*')) aria-current="page" @endif><x-ikona nazwa="chat" /> Wiadomości do nas <x-licznik-kolejki :ile="$kolejki['wiadomosci'] ?? 0" /></a></li>
                                {{-- Konta użytkowników — ekran do WGLĄDU, nie do zarządzania
                                     rolami (te nadaje `kuking:nadaj-role` z powłoki, D-039).
                                     Ostatni w sekcji, bo to jest miejsce, do którego wchodzi
                                     się z pytaniem („kim jest ta osoba"), a nie kolejka, którą
                                     trzeba dziś opróżnić — kolejki zostają na górze. --}}
                                <li><a class="side-nav-item" href="{{ route('admin.users') }}" @if(request()->routeIs('admin.users*')) aria-current="page" @endif><x-ikona nazwa="users" /> Użytkownicy</a></li>
                            </ul>

                            {{--
                                WEJŚCIE W TRYB PANELU — OSOBNA POZYCJA, NIE NAGŁÓWEK
                                SEKCJI (prośba właściciela: „dać oddzielny przycisk,
                                który pokaże tylko menu admina").

                                DLACZEGO NIE ZROBILIŚMY ODNOŚNIKA Z NAGŁÓWKA „Panel
                                moderacji": ten `<h2>` jest nazwą grupy dla czytnika
                                ekranu (`aria-labelledby` wyżej). Nagłówek, który
                                jednocześnie jest odnośnikiem, czyta się jako
                                „Panel moderacji, link, grupa Panel moderacji" —
                                jedno słowo w trzech rolach. Do tego etykieta grupy
                                MUSI zostać etykietą (opisuje sześć pozycji pod
                                spodem), a przycisk MUSI mówić, co zrobi po
                                kliknięciu — a to są dwa różne teksty. Osobna,
                                48-pikselowa pozycja z własnym podpisem robi obie
                                rzeczy uczciwie i nikomu nic nie zabiera.

                                DOKĄD PROWADZI: na „Zgłoszenia". Panel nie ma ekranu
                                startowego (nie ma trasy `admin.index` i tego PR-a
                                tras nie dotyka), a kolejka zgłoszeń jest tym, po co
                                moderator wchodzi do panelu najczęściej. Jak tylko
                                taki ekran powstanie, zmienia się tu jedna trasa.

                                Bez `aria-current` — ten odnośnik nigdy nie wskazuje
                                bieżącego ekranu, bo w trybie panelu w ogóle znika
                                (a poza nim żaden ekran serwisu nim nie jest).
                            --}}
                        </div>
                        @else
                            {{-- Bez `role="group"` i bez `<h2>`: grupa jednego
                                 elementu nie jest grupą, a nagłówek „Panel
                                 moderacji" nad odnośnikiem „Otwórz panel
                                 moderacji" to ta sama nazwa dwa razy pod rząd —
                                 czytnik ekranu przeczytałby ją obie. --}}
                            <a class="side-nav-item side-nav-wejscie" href="{{ route('admin.reports') }}">
                                <x-ikona nazwa="shield" /> Otwórz panel moderacji
                                <x-licznik-kolejki :ile="$czekaWPanelu" />
                            </a>
                        @endif
                    @endif

                    {{--
                        Dół kolumny: obsługa konta, nie treść.

                        CO ZOSTAJE W TRYBIE PANELU I DLACZEGO:
                        * „Powiadomienia" — tam przychodzą zgłoszenia i odpowiedzi
                          w sprawach moderacyjnych, więc dla moderatora to jest
                          narzędzie pracy, nie dodatek do konta;
                        * „Ustawienia" — `/admin/**` wymaga weryfikacji dwuetapowej
                          (`EnsureModeratorHasTwoFactor`), a włącza się ją właśnie
                          w ustawieniach. Menu, które odcina od 2FA, potrafiłoby
                          zamknąć moderatora przed panelem, do którego właśnie
                          próbuje wejść;
                        * „Wyloguj się" — z każdego ekranu serwisu da się wyjść
                          z konta i tryb panelu nie jest tu wyjątkiem.

                        WYPADA „Napisz do nas": to formularz dla użytkownika, który
                        potrzebuje pomocy. Moderator w panelu jest po drugiej
                        stronie tego formularza — jego kolejka nazywa się
                        „Wiadomości do nas" i stoi wyżej, w samym panelu.
                    --}}
                    <ul class="stack-tight list-none p-0 m-0 side-nav-dol">
                        <li><a class="side-nav-item" href="{{ route('notifications.index') }}" @if(request()->routeIs('notifications.*')) aria-current="page" @endif>
                            <x-ikona nazwa="bell" /> Powiadomienia
                            @if($unread > 0)
                                <span class="badge badge-cooked">{{ $unread }}</span>
                                <span class="visually-hidden">nieprzeczytanych</span>
                            @endif
                        </a></li>
                        {{-- Napis „Ustawienia" prowadzi na EKRAN O TYM TYTULE
                             (`settings.index`), a nie na „Czytelność".

                             Do 12 września 2026 stało tu `settings.accessibility`,
                             bo rozdroża nie było — i D-168 przyjęło to jako
                             koszt świadomy, mniejszy niż jeden napis o dwóch
                             różnych celach. Rozdroże powstało (issue #344),
                             więc koszt znika: to samo słowo, ten sam ekran,
                             tu i w rzędzie akcji własnego profilu.

                             `routeIs('settings.*')` zostaje bez zmian —
                             `settings.index` też wpada w ten wzorzec, więc
                             pozycja jest podświetlona na każdym ekranie
                             ustawień, łącznie z samym rozdrożem. --}}
                        <li><a class="side-nav-item" href="{{ route('settings.index') }}" @if(request()->routeIs('settings.*')) aria-current="page" @endif><x-ikona nazwa="settings" /> Ustawienia</a></li>
                        {{-- „Napisz do nas" także tutaj, nie tylko w stopce.
                             Osoba, która się gubi, gubi się na górze ekranu,
                             a nie na jego dole — a stopka na desktopie bywa
                             pod długim feedem. Ikona ma podpis, jak każda
                             pozycja tej nawigacji (AGENTS.md §5). --}}
                        @unless($wTrybiePanelu)
                            <li><a class="side-nav-item" href="{{ route('kontakt') }}" @if(request()->routeIs('kontakt*')) aria-current="page" @endif><x-ikona nazwa="chat" /> Napisz do nas</a></li>
                        @endunless
                        {{-- Wylogowanie stoi na samym dole sekcji „obsługa
                             konta", bo to ostatnia rzecz, jaką się tu robi.
                             Ten sam składnik co na własnym profilu — patrz
                             `components/wyloguj.blade.php`. --}}
                        <li><x-wyloguj class="side-nav-item side-nav-wyloguj"><x-ikona nazwa="logout" /> Wyloguj się</x-wyloguj></li>
                    </ul>
                </nav>
            @endauth

            {{--
                KOLUMNA CZYTANIA MA 45rem NA KAŻDYM EKRANIE.

                Tyle wychodzi 65–75 znaków przy 18–20 px (docs/UX_50_PLUS.md).
                Wcześniej ekran przepisu miał od tego wyjątek (`wide`), bo
                jego dwukolumnowy układ z kitu v2 dusił się w 45rem. Wyjątek
                zniknął razem ze stałą siatką: właściciel zdecydował, że
                szerokość strony ma być identyczna na każdej podstronie, a przy
                stałej siatce `max-width: none` na <main> i tak nie robiło już
                nic — kolumna środkowa ma dokładnie 45rem niezależnie od tego,
                czy dany ekran podaje szynę.

                Jeśli ekran przepisu okaże się przez to za ciasny, właściwą
                odpowiedzią jest oddanie mu KOLUMNY SZYNY (której i tak nie
                używa), a nie rozpychanie całej strony.
            --}}
            <main class="app-main" id="tresc">
                {{-- Komunikaty zwrotne. aria-live, żeby czytnik ekranu je ogłosił.

                     `komunikaty` jest tu po to, żeby układ pasów (strona
                     powitalna) miał co wyśrodkować — jego `<main>` nie ma
                     żadnego wcięcia, bo wcięcia robią same pasy. --}}
                <div class="komunikaty" aria-live="polite">
                    @if(session('status'))
                        <p class="flash">{{ session('status') }}</p>
                    @endif
                </div>
                {{-- Zapis do zeszytu wraca także na strumień bez formularza.
                     Sam worek walidacji nie pokazuje tam błędu (issue #473). --}}
                @php
                    $collectionError = session('errors')?->first('collection_id');
                @endphp
                @if($collectionError)
                    <p id="blad-wyboru-zeszytu" class="notice" role="alert">{{ $collectionError }}</p>
                @endif

                {{--
                    Stan zawieszenia widoczny na KAŻDYM ekranie (issue #40).

                    Osoba zawieszona musi wiedzieć dwie rzeczy bez szukania:
                    że nie może publikować i DO KIEDY. Pokazywanie tego dopiero
                    przy nieudanej próbie publikacji znaczyłoby, że najpierw
                    pisze wpis, a dopiero potem dowiaduje się, że nie ma to sensu.

                    Data po polsku, nie ISO — docs/UX_50_PLUS.md.
                    Bez gry słowem „kuKING" — D-009 zabrania jej w wiadomościach
                    moderacyjnych.
                --}}
                @if(auth()->user()?->isSuspended())
                    <div class="notice" role="status">
                        @if(auth()->user()->status_expires_at)
                            <strong>Twoje konto jest zawieszone do
                                {{ \App\Support\Czas::data(auth()->user()->status_expires_at, 'j F Y') }}.</strong>
                            <span>Do tego czasu możesz czytać, ale nie opublikujesz wpisu ani komentarza.
                                Konto wróci samo — nie musisz nic robić.</span>
                        @else
                            <strong>Twoje konto jest zawieszone.</strong>
                            <span>Do odwołania możesz czytać, ale nie opublikujesz wpisu ani komentarza.
                                Napisz do nas: {{ config('kuking.community.contact_email') }}</span>
                        @endif
                    </div>
                @endif

                {{ $slot }}
            </main>

            @isset($rail)
                {{--
                    PRAWA SZYNA (UI kit v2, ekran 01).

                    `<aside>`, nie `<div>`: czytnik ekranu ma wiedzieć, że to
                    treść poboczna, i móc ją pominąć jednym gestem. Bez tego
                    osoba czytająca linijka po linijce przechodzi przez trzy
                    bloki, zanim dojdzie do stopki.

                    Poniżej 80rem szyna ląduje POD treścią — patrz `.app-rail`
                    w app.css.
                --}}
                <aside class="app-rail" aria-label="Skróty i podpowiedzi">
                    {{ $rail }}
                </aside>
            @endisset
        </div>

        {{--
            LICZNIK SPOŁECZNOŚCI (issue #38, docs/brand/COPY_STYLE.md §8).

            Cała logika — kogo liczymy, cache, próg widoczności, odmiana —
            żyje w `App\Domain\Analytics\LiczbaKukingow`, nie tutaj. Widok
            tylko pyta i, jeśli jest sens, pokazuje wynik.
        --}}
        @php
            $liczbaKukingow = app(\App\Domain\Analytics\LiczbaKukingow::class);
        @endphp

        <footer class="site-footer">
            <div class="site-footer-inner">
                <p class="site-footer-haslo"><x-kuking-word /> — gotujemy po swojemu.</p>

                {{-- Licznik kuKINGów (#38) przeniesiony z płaskiej stopki do
                     poziomu z hasłem — tu jest jego miejsce: mówi, ilu nas
                     jest, więc stoi obok tego, czym jesteśmy, a nie między
                     odnośnikami prawnymi.

                     Osobny <span> na 18 px (`--text-body`), nie ambientowe
                     16 px reszty stopki: to SAMODZIELNA etykieta z liczbą,
                     ten sam przypadek co `.stat-label` na profilu
                     (AGENTS.md §5, `MinimalnyRozmiarTekstuTest`). --}}
                @if($liczbaKukingow->widoczna())
                    <p class="site-footer-liczba">
                        {{ $liczbaKukingow->liczbaSformatowana() }}
                        <x-kuking-word :forma="$liczbaKukingow->sufiks()" />
                    </p>
                @endif


                {{--
                    STOPKA W POZIOMACH (issue #205).

                    Dawniej jeden rząd: hasło, siedem odnośników, wersja
                    i przełącznik motywu — wszystko w jednej linii, przez co
                    wersja i przełącznik wyglądały jak doklejone na końcu.
                    Teraz to trzy poziomy: hasło, kolumny odnośników
                    pogrupowane tematycznie, i na samym dole cienki pasek
                    techniczny (wersja + przełącznik motywu).

                    Grupowanie NIE dokłada ani nie usuwa żadnego odnośnika —
                    to te same siedem (osiem dla zalogowanych) pozycje, co
                    przed zmianą, tylko rozłożone na cztery tematyczne
                    kolumny. Na wąskim ekranie `.site-footer-grupy` (grid
                    `auto-fit`) układa je jedna pod drugą — bez poziomego
                    przewijania, patrz app.css.

                    Każda kolumna to `<nav>` z `aria-label`, żeby czytnik
                    ekranu zapowiedział temat grupy i pozwolił ją pominąć —
                    ten sam powód, dla którego `$rail` wyżej jest `<aside>`,
                    nie `<div>`. Widoczny nagłówek nad linkami jest
                    `aria-hidden`: bez tego czytnik czytałby nazwę grupy
                    dwa razy (raz z `aria-label` nawigacji, raz z tekstu
                    nagłówka). Nie jest to `<h2>`/`<h3>` — stopka nie ma
                    wchodzić w hierarchię nagłówków strony, którą zamyka
                    ostatni nagłówek treści.
                --}}
                <div class="site-footer-grupy">
                    <nav class="site-footer-grupa" aria-label="O serwisie">
                        <p class="site-footer-naglowek" aria-hidden="true">O serwisie</p>
                        <ul>
                            <li><a href="{{ route('about') }}">O <x-kuking-word /></a></li>
                            <li><a href="{{ route('rules') }}">Zasady</a></li>
                        </ul>
                    </nav>

                    <nav class="site-footer-grupa" aria-label="Pomoc i kontakt">
                        <p class="site-footer-naglowek" aria-hidden="true">Pomoc i kontakt</p>
                        <ul>
                            {{--
                                „NAPISZ DO NAS" STOI PIERWSZY W SWOJEJ GRUPIE
                                I NIE JEST DYMKIEM W ROGU EKRANU.

                                Dymek na stałe przyklejony do rogu byłby
                                łatwiejszy do znalezienia dokładnie o tyle,
                                o ile zasłaniałby treść — a przy 320 px
                                i przy czcionce przeglądarki podkręconej do
                                200% zasłania jej najwięcej (WCAG 1.4.10
                                i 2.4.11: element o stałej pozycji potrafi
                                zakryć właśnie sfokusowany przycisk). To
                                repozytorium ma już dwa issues z tej rodziny
                                — #80 i #162 — i oba dotyczyły elementu,
                                który „tylko trochę" wystawał poza ekran.

                                Stopka jest na KAŻDEJ stronie, nie wymaga
                                skryptu, nie zasłania niczego i jest
                                miejscem, w którym osoba 50+ szuka kontaktu
                                odruchowo. Pierwsza pozycja w grupie, bo
                                ważniejsza niż „Pomoc".
                            --}}
                            <li><a href="{{ route('kontakt') }}">Napisz do nas</a></li>
                            <li><a href="{{ route('help') }}">Pomoc</a></li>
                        </ul>
                    </nav>

                    <nav class="site-footer-grupa" aria-label="Sprawy formalne">
                        <p class="site-footer-naglowek" aria-hidden="true">Sprawy formalne</p>
                        <ul>
                            <li><a href="{{ route('terms') }}">Regulamin</a></li>
                            <li><a href="{{ route('privacy') }}">Prywatność</a></li>
                            {{-- DSA art. 16 ust. 1 wymaga mechanizmu ŁATWO
                                 DOSTĘPNEGO. Formularz, do którego nie ma
                                 skąd kliknąć, tego nie spełnia — a przez
                                 chwilę dokładnie taki był: istniał pod
                                 adresem, którego nikt nie miał prawa
                                 znać. --}}
                            <li><a href="{{ route('zglos.nielegalna') }}">Zgłoś nielegalną treść</a></li>
                        </ul>
                    </nav>

                    {{-- Wejście na własne sprawy (issue #10, DSA art. 16
                         ust. 4 i 5). Potwierdzenie przyjęcia i decyzja
                         przychodzą powiadomieniem, ale powiadomienie da się
                         przeoczyć i po trzech miesiącach kasuje je
                         retencja — sprawa żyje trzydzieści sześć. Bez
                         stałego odnośnika człowiek, który zgubił
                         powiadomienie, nie miałby jak wrócić do numeru
                         sprawy. Cała grupa tylko dla zalogowanych: gość nie
                         ma tu żadnych spraw, a odnośnik prowadziłby na
                         ekran logowania. --}}
                    @if($user)
                        <nav class="site-footer-grupa" aria-label="Konto">
                            <p class="site-footer-naglowek" aria-hidden="true">Konto</p>
                            <ul>
                                <li><a href="{{ route('reports.mine') }}">Twoje zgłoszenia</a></li>
                            </ul>
                        </nav>
                    @endif
                </div>

                {{--
                    PASEK TECHNICZNY (wersja + przełącznik motywu).

                    Świadome odstępstwo od AGENTS.md §5 w DWÓCH miejscach —
                    decyzja właściciela, zapisana jako docs/DECISIONS.md,
                    D-051. Reguła („tekst ≥ 18 px", „ikona nigdy sama")
                    zostaje w mocy wszędzie indziej; tu jest jawnie
                    udokumentowanym wyjątkiem, nie przeoczeniem.
                --}}
                <div class="site-footer-pasek">
                    {{--
                        SZYBKI PRZEŁĄCZNIK MOTYWU (docs/DECISIONS.md,
                        D-019, D-051).

                        W stopce, bo stopka jest na KAŻDEJ stronie i widoczna
                        też na telefonie — w przeciwieństwie do pełnego
                        ustawienia na `/ustawienia/czytelnosc`, do którego na
                        telefonie nie ma dziś dojścia bez zalogowania. Działa
                        też dla gościa: nie ma tu `@auth`.

                        ZWYKŁY FORMULARZ POST, NIE LINK GET (AGENTS.md §5,
                        §7): zmiana stanu przez GET dałaby się wywołać samym
                        linkiem (np. z prefetchu przeglądarki) i złamałaby
                        CSRF.

                        SAMA IKONA, NIE WIDOCZNY NAPIS (D-051) — świadomy
                        wyjątek od „ikona nigdy sama" (AGENTS.md §5), na
                        wyraźne życzenie właściciela (issue #205), żeby
                        przełącznik zajmował mało miejsca w pasku. Trzy
                        rzeczy, których ten wyjątek NIE rusza:

                          1. `aria-label` i `title` niosą DOKŁADNIE ten sam
                             tekst, co dawny widoczny napis („Włącz ciemny
                             wygląd" / „Włącz jasny wygląd") — nazwa
                             dostępna zostaje, znika tylko jej wizualny
                             odpowiednik.
                          2. `<span class="visually-hidden">` zostaje —
                             podwójne, ale tanie zabezpieczenie na wypadek,
                             gdyby `aria-label` kiedyś zniknął przy
                             refaktorze.
                          3. Pole kliknięcia zostaje ≥48×48 px: `.btn`
                             wymusza `min-height: 3rem`, a padding poziomy
                             (`--spacing-5` z każdej strony) daje mu przy
                             ikonie 24 px szerokość znacznie powyżej progu —
                             to, co zajmowało miejsce, było napisem obok
                             ikony, nie wymiarem samego przycisku.

                        IKONA POKAZUJE WYNIK KLIKNIĘCIA, SPÓJNIE Z TEKSTEM.
                        Jasny motyw → napis „Włącz ciemny wygląd" → ikona
                        `ksiezyc`. Ciemny motyw → napis „Włącz jasny wygląd"
                        → ikona `slonce`. Nie odwrotnie: kształt ma pokazywać
                        DOKĄD prowadzi kliknięcie, tak samo jak dziś robi to
                        napis, nie stan bieżący.

                        DWA NOWE KSZTAŁTY W `<x-ikona>`, NIE `settings`
                        (zębatka). Pierwsza wersja tego PR-a użyła `settings`
                        jako „najbliższego sensownego zamiennika", bo zestaw
                        nie miał księżyca/słońca. To był błąd: `settings` to
                        DOKŁADNIE ta sama zębatka, co pozycja „Ustawienia"
                        w menu bocznym (patrz `<li>` z `route('settings.*')`
                        wyżej w tym pliku) — czyli po zmianie w serwisie
                        byłyby dwa różne przyciski o tym samym kształcie.
                        Przy zwykłym przycisku z napisem dałoby się to
                        wybaczyć; przy przełączniku BEZ widocznego napisu
                        (patrz wyżej) kształt jest JEDYNĄ wskazówką, co
                        przycisk robi — więc pożyczony kształt jest zwykłą
                        pomyłką do kliknięcia, nie oszczędnością. Stąd
                        `ksiezyc` i `slonce` jako osobne, jednoznaczne
                        kształty w `ikona.blade.php`.

                        `redirect_to` NIE istnieje: `back()`
                        w ThemeController czyta nagłówek `Referer`, tak samo
                        jak każdy inny formularz „Zapisz" w serwisie (np.
                        AccessibilitySettingsController).
                    --}}
                    <form method="POST" action="{{ route('theme.update') }}" class="site-footer-motyw">
                        @csrf
                        <input type="hidden" name="theme" value="{{ $theme === 'dark' ? 'light' : 'dark' }}">
                        <span class="visually-hidden">Wygląd strony: {{ $theme === 'dark' ? 'ciemny' : 'jasny' }}.</span>
                        @php
                            $motywEtykieta = $theme === 'dark' ? 'Włącz jasny wygląd' : 'Włącz ciemny wygląd';
                            $motywIkona = $theme === 'dark' ? 'slonce' : 'ksiezyc';
                        @endphp
                        <button
                            class="btn btn-quiet site-footer-motyw-przycisk"
                            type="submit"
                            aria-label="{{ $motywEtykieta }}"
                            title="{{ $motywEtykieta }}"
                        >
                            <x-ikona :nazwa="$motywIkona" />
                        </button>
                    </form>

                    {{-- Wersja: etap produktu, DATA I GODZINA WYDANIA, skrót
                         wdrożonego commita. Widoczna zawsze, żeby dało się
                         jednym spojrzeniem sprawdzić, co dokładnie działa na
                         tej stronie.

                         ROZMIAR 8 PX (D-051) — świadomy wyjątek od
                         AGENTS.md §5 („tekst ≥ 18 px"), na wyraźne życzenie
                         właściciela: metryczka ma być „małym druczkiem" na
                         samym dole stopki. Kontrast NIE jest częścią tego
                         wyjątku — `--color-ink-muted` na
                         `--color-surface-raised` liczy 7,54:1
                         (docs/design/DESIGN_SYSTEM.md), więc zmiana samego
                         rozmiaru nie psuje czytelności koloru. Rozmiar
                         nadal skaluje się z `--user-text-scale`
                         (`/ustawienia/czytelnosc`) tak jak reszta serwisu —
                         inaczej osoba, która celowo powiększyła sobie tekst,
                         dostałaby tu jedyne miejsce w serwisie, którego to
                         ustawienie nie dotyczy.

                         Etap produktu w `<strong>`, bo to on odpowiada na
                         pytanie „na czym w ogóle patrzę" i ma się rzucać
                         w oczy bardziej niż reszta. Data przed skrótem, bo
                         to ją czyta człowiek; skrót zostaje, żeby dało się
                         powiedzieć, którego commita dotyczy zgłoszona usterka
                         (`App\Support\Wersja`). Do 10 września 2026 stało tu
                         „skrót zostaje dla Sentry" — Sentry'ego w projekcie
                         nie ma i nigdy nie było (D-041).

                         Bez `title` z pełnym skrótem: na telefonie nie ma
                         najazdu kursorem, a informacja dostępna tylko przez
                         hover jest dla części osób niedostępna w ogóle
                         (UX_50_PLUS). Widoczna zawsze — nie chowamy jej pod
                         hover ani pod `title`. --}}
                    <span class="site-version">
                        <strong class="site-version-etap">{{ \App\Support\Wersja::etykieta() }}</strong>
                        <span class="site-version-wydanie">{{ \App\Support\Wersja::opisWydania() }}</span>
                    </span>
                </div>
            </div>
        </footer>

        {{--
            Powiększone zdjęcie.

            Natywny `<dialog>`, nie własna nakładka z div-ów. Przeglądarka daje
            za darmo rzeczy, które robi się źle ręcznie: pułapkę focusu,
            zamykanie klawiszem Escape, poprawną rolę dla czytników ekranu
            i tło blokujące klikanie tego, co pod spodem.

            Element jest PUSTY do momentu otwarcia — zdjęcie wstawia skrypt.
            Dzięki temu strona nie pobiera dużych wariantów wszystkich zdjęć
            „na zapas", co przy feedzie z dwudziestoma pozycjami byłoby
            kilkunastoma megabajtami na łączu, którego nikt nie prosił.

            Bez JavaScriptu ten `<dialog>` nigdy się nie otwiera i nie
            przeszkadza — kliknięcie w zdjęcie po prostu otwiera duży wariant
            na osobnej stronie.
        --}}
        <dialog id="powiekszenie" class="lightbox" aria-label="Powiększone zdjęcie">
            <img class="lightbox-obraz" src="" alt="">

            {{-- Przycisk z NAPISEM, nie samym „×”. AGENTS.md: ikona nigdy sama. --}}
            <form method="dialog" class="lightbox-akcje">
                <button class="btn btn-secondary" type="submit">Zamknij</button>
            </form>
        </dialog>
    </div>

    @auth
        @if($wTrybiePanelu)
            {{--
                TELEFON W TRYBIE PANELU — PASEK DOLNY MA JEDNO ZADANIE: WYJŚCIE.

                Zwykła piątka (Start, Szukaj, Dodaj, Moje, Profil) to dokładnie
                te przyciski, których w tym trybie ma nie być — zostawienie ich
                na telefonie znaczyłoby, że prośba właściciela jest spełniona
                tylko na dużym ekranie.

                Ale pasek dolny nie może po prostu zniknąć: to na telefonie
                jedyne miejsce w zasięgu kciuka i jedyna nawigacja, jaką widać
                bez przewijania na sam dół. Zostaje więc z jedną pozycją —
                wyjściem — na całą szerokość. Moderator nie zostaje w trybie
                panelu zamknięty.

                Same ekrany panelu są na telefonie osiągalne z menu nad treścią:
                `.side-nav-tryb-panelu` jest tam widoczne (patrz app.css) —
                inaczej niż zwykłe menu boczne, którego na telefonie nie ma.
                To jest przy okazji pierwsza sensowna nawigacja po panelu
                na telefonie w ogóle: dotąd nie było żadnej.
            --}}
            <nav class="bottom-nav bottom-nav-panel" aria-label="Wyjście z panelu moderacji">
                <a class="bottom-nav-item" href="{{ route('home') }}">
                    <x-ikona nazwa="home" class="bottom-nav-icon" :rozmiar="26" /> Wróć do Kuking
                </a>
            </nav>
        @else
        <nav class="bottom-nav" aria-label="Nawigacja główna">
            <a class="bottom-nav-item" href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>
                <x-ikona nazwa="home" class="bottom-nav-icon" :rozmiar="26" /> Start
            </a>
            <a class="bottom-nav-item" href="{{ route('search') }}" @if(request()->routeIs('search')) aria-current="page" @endif>
                <x-ikona nazwa="search" class="bottom-nav-icon" :rozmiar="26" /> Szukaj
            </a>
            {{-- Główna akcja produktu ma w kicie wyróżniony, okrągły znak
                 na środku paska. Podpis „Dodaj" ZOSTAJE pod spodem: kółko jest
                 wyróżnieniem, nie zastąpieniem napisu (AGENTS.md §5). --}}
            <a class="bottom-nav-item bottom-nav-item-glowna" href="{{ route('add') }}" @if($naDodaj) aria-current="page" @endif>
                <span class="bottom-nav-kolko"><x-ikona nazwa="plus" class="bottom-nav-icon" :rozmiar="26" /></span> Dodaj
            </a>
            <a class="bottom-nav-item" href="{{ route('collections.index') }}" @if(request()->routeIs('collections.*')) aria-current="page" @endif>
                <x-ikona nazwa="book" class="bottom-nav-icon" :rozmiar="26" /> Moje
            </a>
            <a class="bottom-nav-item" href="{{ route('profile.show', $user->profile->username) }}" @if(request()->routeIs('profile.show')) aria-current="page" @endif>
                <x-ikona nazwa="user" class="bottom-nav-icon" :rozmiar="26" /> Profil
            </a>
        </nav>
        @endif
    @endauth

    @if($livewire)
        @livewireScripts
    @endif
</body>
</html>
