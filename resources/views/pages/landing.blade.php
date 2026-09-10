{{--
    STRONA POWITALNA — układ pasów (system projektowy v3.1, `site.css` §1).

    Pas to sekcja na całą szerokość okna z własnym tłem; szerokość treści
    pilnuje `.pas-wnetrze`. Zmiana tła między pasami mówi „to nowa myśl" bez
    ani jednego słowa — i działa też wtedy, gdy ktoś przewija stronę szybko
    albo ogląda ją z odległości wyciągniętej ręki.

    CO SIĘ ZMIENIŁO WOBEC POPRZEDNIEJ WERSJI
    Była jedna kolumna na jednym tle: hasło, dwie karty, lista wpisów. Ta
    strona jest pierwszym, co widzi człowiek, który o Kuking nie wie nic —
    a nie mówiła, co Kuking robi, tylko od razu pokazywała cudze obiady.

    KOLEJNOŚĆ PASÓW JEST ARGUMENTEM, NIE OZDOBĄ
      1. hasło i dwa przyciski — co to jest i co można zrobić teraz,
      2. cztery rzeczy — co ten serwis robi, wypisane wprost,
      3. „Ugotowałem" na ciemnym — jedna rzecz, której nie ma nigdzie indziej
         (`docs/DECISIONS.md`, D-004: ugotowanie jest ważniejsze niż lajk),
      4. „Świeżo z Kuking" — dopiero teraz cudze wpisy, bo dopiero teraz
         wiadomo, na co się patrzy,
      5. dane i prywatność — co się dzieje z tym, co dodasz,
      6. załóż konto.

    Każde zdanie ma pokrycie w kodzie. „Pobierzesz paczkę" — eksport danych
    (`DataSettingsController`). „Sam decydujesz, kto widzi wpis" — trzy
    poziomy widoczności w `Post` (public / followers / private). „Nie pytamy
    o numer telefonu ani o datę urodzenia" — formularz rejestracji ma cztery
    pola i dwa potwierdzenia. Obietnica bez pokrycia na tej stronie kosztuje
    więcej niż brak obietnicy.
--}}
<x-layout
    :powitalny="true"
    title="Pokaż, co dziś ugotowałeś"
    description="Kuking to polska społeczność ludzi, którzy naprawdę gotują. Wrzuć zdjęcie obiadu, zapisz przepisy po mamie i zobacz, komu z Twojego przepisu wyszło.">

    {{-- 1. HASŁO ------------------------------------------------------- --}}
    <section class="pas">
        <div class="pas-wnetrze hero">
            <div class="hero-tekst">
                <p class="nadtytul">Gotujemy po swojemu.</p>
                <h1 class="hero-tytul text-title-xl">Pokaż, co dziś ugotowałeś</h1>
                <p class="text-lead hero-lead miara">
                    Kuking to miejsce dla ludzi, którzy gotują naprawdę — w swojej kuchni,
                    z tego, co jest. Wrzucasz zdjęcie i kilka słów. Nic więcej nie musisz.
                </p>
                <div class="hero-akcje">
                    <a class="btn btn-primary btn-duzy" href="{{ route('register') }}">Zostań <x-kuking-word forma="iem" /> — to darmowe</a>
                    <a class="btn btn-secondary" href="{{ route('discover') }}">Najpierw się rozejrzę</a>
                </div>
            </div>

            {{-- Tablica dnia zamiast zdjęcia z systemu projektowego. System
                 stawia tu fotografię potrawy; my mamy w tym miejscu coś
                 lepszego niż zdjęcie poglądowe — prawdziwych ludzi, wpisy
                 i notatki z dzisiaj. Zdjęcie z pliku byłoby dekoracją,
                 tablica jest treścią. --}}
            <div class="hero-figura">
                {{-- `:graSlowem="false"` — na tym ekranie gra słowem „kuKING" jest już
                     zużyta przez przycisk „Zostań kuKINGiem" wyżej, a
                     `docs/brand/COPY_STYLE.md` §2 dopuszcza ją najwyżej raz na ekran.
                     Tablica dostaje więc nagłówek zapasowy z §5. --}}
                <x-kuking-board :people="$board['people']" :posts="$board['posts']" :notes="$board['notes']" :graSlowem="false" />
            </div>
        </div>
    </section>

    {{-- 2. CZTERY RZECZY ------------------------------------------------ --}}
    <section class="pas pas--wglebiony">
        <div class="pas-wnetrze">
            <h2 class="text-title-lg">Cztery rzeczy i nic więcej</h2>
            <p class="text-lead miara">Kuking robi cztery rzeczy porządnie i nie próbuje robić trzynastej.</p>

            <ul class="rzeczy odstep-nad">
                <li class="rzecz">
                    <p class="rzecz-tytul">Pokazujesz, co ugotowałeś</p>
                    <p class="rzecz-opis">Zdjęcie i kilka słów. Nie musi być ładne — ma być prawdziwe.</p>
                </li>
                <li class="rzecz">
                    <p class="rzecz-tytul">Trzymasz przepisy w Zeszycie</p>
                    <p class="rzecz-opis">Przepis po mamie albo po babci możesz podpisać, po kim jest, dopisać historię i dodać zdjęcie starej kartki. Zeszyt z przepisami można zgubić — tego nie zgubisz.</p>
                </li>
                <li class="rzecz">
                    <p class="rzecz-tytul">Mówisz, że ugotowałeś</p>
                    <p class="rzecz-opis">Kiedy ugotujesz z czyjegoś przepisu, autor się o tym dowie. To jest tutaj najmilsza rzecz.</p>
                </li>
                <li class="rzecz">
                    <p class="rzecz-tytul">Obserwujesz, kogo chcesz</p>
                    <p class="rzecz-opis">Widzisz to, co gotują osoby, które obserwujesz. W kolejności, w jakiej to dodali — bez żadnego układania po swojemu.</p>
                </li>
            </ul>
        </div>
    </section>

    {{-- 3. „UGOTOWAŁEM" ------------------------------------------------- --}}
    <section class="pas blok-ciemny">
        <div class="pas-wnetrze pas-ciemny-uklad">
            <div class="pas-ciemny-tekst">
                <h2 class="text-title-lg">Przepis jest dobry wtedy, kiedy ktoś go ugotował</h2>
                <p class="text-lead">
                    Pod każdym przepisem jest przycisk „Ugotowałem". Kiedy go naciśniesz i dodasz zdjęcie,
                    autor przepisu dowie się, że ktoś naprawdę zrobił to u siebie w kuchni.
                </p>
                <p>
                    Dlatego przy przepisie widać nie liczbę serduszek, tylko zdjęcia od ludzi, którym wyszedł.
                    Nie ma tu rankingów. Nie ma kogo wyprzedzać.
                </p>
            </div>

            {{-- Brzmienie WZIĘTE Z KODU, nie wymyślone: tak renderuje je
                 `pages/notifications.blade.php` dla `Notification::TYPE_COOKED`.
                 Wcześniej stała tu parafraza („Halina ugotowała Twój rosół")
                 podpisana „tak wygląda powiadomienie" — czyli obietnica
                 o jedno słowo mocniejsza niż kod pod nią.

                 Cytat zmienił się razem z powiadomieniem (issue #38): tamto
                 zdanie miało w środku „ugotowała/ugotował", czego nie da się
                 przeczytać na głos, więc `COPY_STYLE.md` §2 każe zmienić
                 konstrukcję zamiast wybierać rodzaj. Kopia tu ma się nie
                 rozjechać z oryginałem — pilnuje tego
                 `TekstyWedlugCopyStyleTest::test_cytat_na_stronie_powitalnej_zgadza_sie_z_powiadomieniem`. --}}
            <blockquote class="cytat-ugotowalem">
                Halina — ugotowane z Twojego przepisu „Rosół babci".
                <span class="cytat-zrodlo">Na to powiadomienie się tutaj czeka.</span>
            </blockquote>
        </div>
    </section>

    {{-- 4. ŚWIEŻO Z KUKING ---------------------------------------------- --}}
    <section class="pas pas--kreska-gora">
        <div class="pas-wnetrze">
            <h2 class="text-title-lg">Świeżo z Kuking</h2>
            <p class="text-lead miara">To, co ludzie ugotowali w ostatnich dniach.</p>

            @if($posts->count() === 0)
                <div class="odstep-nad">
                    <x-empty-state title="Kuking dopiero się zaczyna">
                        Jeszcze nic tu nie ma. Jeśli lubisz gotować, możesz być jedną z pierwszych osób,
                        które tu coś pokażą.
                    </x-empty-state>
                </div>
            @else
                {{-- Tylko `landing-wpisy`, BEZ `stack`. `.stack` to margines na
                     dzieciach, a nie flex — w siatce dodawałby się do `gap`
                     i pierwsza karta w rzędzie miałaby inny odstęp niż druga. --}}
                <div class="landing-wpisy odstep-nad">
                    @foreach($posts as $post)
                        <x-post-card :post="$post" />
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- 5. TWOJE DANE --------------------------------------------------- --}}
    <section class="pas pas--cieply">
        <div class="pas-wnetrze">
            <h2 class="text-title-lg">Zabierzesz stąd wszystko, co dodasz</h2>

            <div class="dwie-kolumny odstep-nad">
                <div>
                    <p>
                        W każdej chwili możesz zamówić paczkę ze swoimi zdjęciami, wpisami
                        i przepisami — przygotujemy ją i damy znać, kiedy będzie do pobrania.
                        Otworzysz ją na swoim komputerze, także wtedy, gdyby Kuking kiedyś
                        przestał istnieć.
                    </p>
                    <p>
                        Przy każdym wpisie sam decydujesz, kto go widzi: wszyscy, tylko obserwujący albo tylko Ty.
                    </p>
                </div>
                <div>
                    <p><strong>Prowadzimy to na własną rękę.</strong></p>
                    <p>
                        Nie ma tu reklam między daniami ani firmy, która czeka na Twoje dane —
                        jest strona, konto i przepisy.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- 6. ZAŁÓŻ KONTO -------------------------------------------------- --}}
    <section class="pas pas--kreska-gora">
        <div class="pas-wnetrze zacheta">
            <h2 class="text-title-lg">Załóż konto. Zajmie minutę</h2>
            <p class="text-lead zacheta-tekst">
                Cztery pola i dwa potwierdzenia: że masz ukończone
                {{ config('kuking.account.min_age') }} lat i że znasz regulamin.
                Nie pytamy o numer telefonu ani o datę urodzenia.
            </p>
            <div class="zacheta-akcje">
                <a class="btn btn-primary btn-duzy" href="{{ route('register') }}">Załóż konto</a>
                <span class="pomoc">Masz już konto? <a href="{{ route('login') }}">Zaloguj się</a>.</span>
            </div>
        </div>
    </section>
</x-layout>
