{{--
    STRONA POWITALNA — układ pasów (system projektowy v3.1, `site.css` §1).

    Pas to sekcja na całą szerokość okna z własnym tłem; szerokość treści
    pilnuje `.pas-wnetrze`. Zmiana tła między pasami mówi „to nowa myśl" bez
    ani jednego słowa — i działa też wtedy, gdy ktoś przewija stronę szybko
    albo ogląda ją z odległości wyciągniętej ręki.

    KOLEJNOŚĆ PASÓW JEST ARGUMENTEM, NIE OZDOBĄ
      1. hasło i dwa przyciski — co to jest i co można zrobić teraz,
      2. „Jak działa" — trzy krótkie kroki,
      3. tablica „kuKINGi na dziś" — dowód, że tu naprawdę ktoś gotuje,
      4. „Ugotowałem" na ciemnym — jedna rzecz, której nie ma nigdzie indziej
         (`docs/DECISIONS.md`, D-004: ugotowanie jest ważniejsze niż lajk),
      5. „Świeżo z Kuking" — dopiero teraz cudze wpisy, bo dopiero teraz
         wiadomo, na co się patrzy,
      6. dane i prywatność — co się dzieje z tym, co dodasz,
      7. załóż konto.

    ZMIANA WOBEC POPRZEDNIEJ WERSJI (audyt 60+, `docs/research/AUDYT_60_PLUS.md`)
    Tablica stała w sekcji 1, obok hasła — czyli najgęstsza, interaktywna
    część ekranu (osoby do zaobserwowania, miniatury, przyciski) pojawiała
    się PRZED jakimkolwiek prostym wyjaśnieniem, co tu w ogóle można robić.
    Nowa osoba musiała odfiltrować to wszystko, zanim zbudowała sobie model
    „co tu robię i jaki jest mój następny krok". Właściciel wybrał obie drogi
    naprawy naraz: „Jak działa" (dawne „Cztery rzeczy i nic więcej", skrócone
    do trzech zdań) stoi teraz PRZED tablicą, a sama tablica pokazuje gościowi
    mniej kart niż wcześniej (`FeedController::GUEST_BOARD_PEOPLE`/`_POSTS`).
    Po zalogowaniu tablica jest nietknięta — ten limit dotyczy wyłącznie tego
    kontrolera i tego widoku.

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
                    {{-- `btn-napis` NIE JEST OZDOBNIKIEM — patrz issue #353 i komentarz
                         przy `.btn-napis` w `resources/css/tokens.css`. `.btn` jest
                         `inline-flex`, więc bez tego `<span>` napis to trzy elementy
                         flex („Zostań ", nazwa, „ — to darmowe"), każdy zawijany osobno
                         i łamany w środku wyrazu. --}}
                    <a class="btn btn-primary btn-duzy" href="{{ route('register') }}"><span class="btn-napis">Zostań <x-kuking-word forma="iem" /> — to darmowe</span></a>
                    <a class="btn btn-secondary" href="{{ route('discover') }}">Najpierw się rozejrzę</a>
                </div>
            </div>

            {{-- KOLAŻ ZDJĘĆ ------------------------------------------------
                 Zgłoszenie właściciela: „na stronie głównej na samej górze po
                 prawej stronie można zrobić kolaż w którym będą najładniejsze
                 (albo wybrane przez admina) zdjęcia użytkowników, żeby
                 zachęcać od razu". Do 11 września hero miało JEDNO dziecko
                 i ani jednego zdjęcia — przy paśmie 1040 px treść zajmowała
                 544 px, więc pół szerokości stało puste. Serwis o gotowaniu
                 witał bez jedzenia.

                 STAN ZAPASOWY JEST W `App\Domain\Feed\HeroKolaz`, NIE TUTAJ.
                 Widok zna dokładnie dwa przypadki: są cztery kafle albo nie
                 ma ich wcale. Kolekcja nigdy nie przychodzi niepełna — układ
                 siatki jest zazębiony i brakujący kafel zostawiłby w hero
                 dziurę w swoim kształcie, a nie mniejszy kolaż. Pełne
                 uzasadnienie kolejności „wybór gospodarza → dobór
                 automatyczny → brak kolażu" stoi w docblocku tamtej klasy.

                 DLACZEGO TO JEST OZDOBNIK (`alt=""` + `aria-hidden`)
                 Rozstrzygnięcie, nie odruch. Te zdjęcia nie są odnośnikiem,
                 nie mają podpisu przy sobie, nie da się z nich nigdzie przejść
                 i nie niosą ani jednej informacji, której nie ma w zdaniu
                 obok („miejsce dla ludzi, którzy gotują naprawdę — w swojej
                 kuchni"). Cztery niepowiązane opisy dań przeczytane na głos
                 PRZED przyciskiem „Zostań kuKINGiem" nie informują, tylko
                 odsuwają człowieka od jedynej akcji tego ekranu — a część
                 zdjęć w serwisie i tak nie ma wpisanego `alt_text`, więc
                 „opisy" znaczyłoby w praktyce „cztery razy to samo zdanie
                 zastępcze". Ozdobnik zamiast treści jest tu decyzją na
                 korzyść czytającego ekranem, nie oszczędnością.

                 PODPIS POD KOLAŻEM NIE JEST OZDOBNIKIEM i celowo stoi POZA
                 `aria-hidden`. To są nazwiska ludzi, których zdjęcia właśnie
                 pokazujemy na stronie zachęcającej do rejestracji; prawo do
                 oznaczenia autorstwa jest prawem osobistym i nie przenosi go
                 żadna licencja (projekt klauzuli UGC, §10). Jedno zdanie
                 kosztuje tu mniej niż rozmowa o tym, dlaczego go nie ma.

                 WYDAJNOŚĆ: to jest pierwsza rzecz, jaką ładuje gość.
                 Wariant `thumb` (320 px), nie `feed` ani oryginał — największy
                 kafel ma na paśmie 1040 px około 230 px szerokości, więc 320 px
                 starcza także przy gęstszym ekranie. `loading="lazy"` na
                 wszystkich czterech, bo poniżej 64rem kolaż jest ukryty:
                 przeglądarka nie pobiera wtedy ani jednego z tych plików,
                 czyli telefon nie płaci za obrazki, których nie zobaczy.
                 `decoding="async"` zdejmuje dekodowanie z wątku układu. --}}
            @if($kolaz->isNotEmpty())
                @php
                    $autorzyKolazu = $kolaz->pluck('autor')
                        ->map(fn ($autor) => $autor->displayName())
                        ->unique()
                        ->values();

                    $podpisKolazu = $autorzyKolazu->count() > 1
                        ? $autorzyKolazu->slice(0, -1)->implode(', ').' i '.$autorzyKolazu->last()
                        : (string) $autorzyKolazu->first();
                @endphp

                <figure class="hero-kolaz-blok">
                    <div class="hero-kolaz" aria-hidden="true">
                        @foreach($kolaz as $kafel)
                            <img class="hero-kolaz-kafel"
                                 src="{{ $kafel['media']->url('thumb') }}"
                                 alt=""
                                 width="{{ $kafel['media']->width('thumb') ?? 320 }}"
                                 height="{{ $kafel['media']->height('thumb') ?? 320 }}"
                                 loading="lazy"
                                 decoding="async">
                        @endforeach
                    </div>
                    <figcaption class="hero-kolaz-podpis">
                        Zdjęcia od: {{ $podpisKolazu }}.
                    </figcaption>
                </figure>
            @endif
        </div>
    </section>

    {{-- 2. JAK DZIAŁA ------------------------------------------------------
         Dawne „Cztery rzeczy i nic więcej", skrócone do trzech zdań (audyt
         60+ — patrz komentarz na górze pliku). Stoi PRZED tablicą specjalnie:
         to ma być pierwsza rzecz, która buduje prosty model „co tu robię",
         zanim człowiek zobaczy gęstą, interaktywną listę osób i dań.

         PRZEBUDOWANE 11 WRZEŚNIA — zgłoszenie właściciela, dosłownie: „zbyt
         brzydkie, proste i niewizualne". Zmierzone przed zmianą: trzy kafle
         w dwóch kolumnach od 48rem, czyli układ 2+1 z PUSTYM POLEM wielkości
         całej karty pod trzecim kaflem. Kafel był białym prostokątem
         z pogrubionym napisem „Krok 1" i jednym zdaniem — zero rytmu i zero
         obrazu w sekcji, która ma wytłumaczyć, po co tu w ogóle jesteśmy.

         CO SIĘ ZMIENIŁO I DLACZEGO TYLE
           * TRZY KOLUMNY OD 64REM, nie dwie od 48rem. Trzy kroki to trzy
             rzeczy równorzędne i układ ma to pokazywać; 2+1 mówił oku, że
             dwa pierwsze są parą, a trzeci dokładką — i zostawiał tę dziurę.
           * PONIŻEJ 64REM KAFEL JEST POZIOMY: znak po lewej, tekst po prawej.
             Jedna kolumna wysokich kafli byłaby na telefonie trzema ekranami
             przewijania przed tablicą.
           * ZNAK (koło z ikoną) zamiast samego napisu „Krok 1". To jest cała
             „wizualność", o którą prosił właściciel, i jest tania: kształty
             są z istniejącego zestawu (`components/ikona.blade.php`), więc
             nie dokładamy ani jednego pliku graficznego do pobrania.
           * NUMER KROKU ZOSTAJE TEKSTEM, nie przechodzi do ikony. Ikona jest
             `aria-hidden` z założenia (patrz docblock komponentu) i nie może
             być jedynym nośnikiem kolejności — „Krok 2" musi dać się
             przeczytać na głos.

         Kolejność treści w kaflu: numer → co robisz → jak to wygląda.
         Nagłówek mówi teraz CZYNNOŚĆ („Robisz zdjęcie"), a nie pozycję na
         liście — samo „Krok 1" nie było tytułem, tylko etykietą. --}}
    <section class="pas pas--wglebiony" id="jak-dziala">
        <div class="pas-wnetrze">
            <h2 class="text-title-lg">Jak działa</h2>

            <ol class="rzeczy odstep-nad">
                <li class="rzecz">
                    <span class="rzecz-znak"><x-ikona nazwa="image" :rozmiar="30" /></span>
                    <div class="rzecz-tresc">
                        <p class="rzecz-krok">Krok 1</p>
                        <p class="rzecz-tytul">Robisz zdjęcie</p>
                        <p class="rzecz-opis">Telefonem, prosto z garnka. Nie musi być z okładki.</p>
                    </div>
                </li>
                <li class="rzecz">
                    <span class="rzecz-znak"><x-ikona nazwa="book" :rozmiar="30" /></span>
                    <div class="rzecz-tresc">
                        <p class="rzecz-krok">Krok 2</p>
                        <p class="rzecz-tytul">Piszesz kilka słów</p>
                        <p class="rzecz-opis">Co to jest i z czego. Tyle wystarczy.</p>
                    </div>
                </li>
                <li class="rzecz">
                    <span class="rzecz-znak"><x-ikona nazwa="chat" :rozmiar="30" /></span>
                    <div class="rzecz-tresc">
                        <p class="rzecz-krok">Krok 3</p>
                        <p class="rzecz-tytul">Ktoś odpowiada</p>
                        <p class="rzecz-opis">Komentarzem albo „Ugotowałem” — czyli zdjęciem tego samego dania ze swojej kuchni.</p>
                    </div>
                </li>
            </ol>
        </div>
    </section>

    {{-- 3. TABLICA „KUKINGI NA DZIŚ" ---------------------------------------
         Tablica dnia zamiast zdjęcia z systemu projektowego. System stawia
         tu fotografię potrawy; my mamy w tym miejscu coś lepszego niż
         zdjęcie poglądowe — prawdziwych ludzi, wpisy i notatki z dzisiaj.

         Do tej sekcji `$board['people']`/`$board['posts']` przychodzą już
         obcięte przez `FeedController::landing()` do liczby ustalonej TYLKO
         dla gościa — `App\Http\Controllers\FeedController::GUEST_BOARD_PEOPLE`/
         `GUEST_BOARD_POSTS`. Ten sam komponent na `/home`, `/odkryj`
         i `/szukaj` dostaje pełną tablicę z `DailyBoard` bez tego obcięcia. --}}
    <section class="pas pas--kreska-gora">
        <div class="pas-wnetrze">
            {{-- `:graSlowem="false"` — na tym ekranie gra słowem „kuKING" jest już
                 zużyta przez przycisk „Zostań kuKINGiem" wyżej, a
                 `docs/brand/COPY_STYLE.md` §2 dopuszcza ją najwyżej raz na ekran.
                 Tablica dostaje więc nagłówek zapasowy z §5. --}}
            <x-kuking-board :people="$board['people']" :posts="$board['posts']" :notes="$board['notes']" :graSlowem="false" />
        </div>
    </section>

    {{-- 4. „UGOTOWAŁEM" ------------------------------------------------- --}}
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

    {{-- 5. ŚWIEŻO Z KUKING ---------------------------------------------- --}}
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
                {{-- `landing-wpisy-kolumna`, nie dawna `landing-wpisy` (siatka
                     dwukolumnowa): zgłoszenie właściciela o wpisach „jedno pod
                     drugim" i o zmarnowanej przestrzeni między nimi. Pomiar obu
                     układów i uzasadnienie wyboru stoją przy tej klasie
                     w `resources/css/strony-publiczne.css`.

                     Bez `stack`. `.stack` to margines na dzieciach, a nie flex —
                     w siatce dodawałby się do `gap`. --}}
                <div class="landing-wpisy-kolumna odstep-nad">
                    @foreach($posts as $post)
                        <x-post-card :post="$post" />
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- 6. TWOJE DANE --------------------------------------------------- --}}
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
                    {{-- „sam decydujesz" przypisywało czytelnikowi rodzaj męski
                         (issue #38, COPY_STYLE.md §2) — „sam" nie wnosi tu
                         informacji. --}}
                    <p>
                        Przy każdym wpisie decydujesz, kto go widzi: wszyscy, tylko obserwujący albo tylko Ty.
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

    {{-- 7. ZAŁÓŻ KONTO -------------------------------------------------- --}}
    <section class="pas pas--kreska-gora">
        <div class="pas-wnetrze zacheta">
            {{-- BEZ „Zajmie minutę": to obietnica z miarą, której nie mierzymy.
                 Zdanie pod spodem mówi to samo bez obietnicy — wymienia,
                 z czego ta rejestracja się składa. --}}
            <h2 class="text-title-lg">Załóż konto</h2>
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
