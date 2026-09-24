{{-- D-208: kompozycja strony publicznej ze wzorca właściciela.
     Kroki → Ugotowałem → prawdziwa tablica → wpisy → dane → rejestracja.
     Zdjęcie w bloku Ugotowałem jest ilustracją z publicznego kolażu,
     nie deklaracją wykonania konkretnego przepisu. Filtry i autorstwo zostają. --}}
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
                {{-- „gotują naprawdę" i „Nic więcej nie musisz" wypadły
                     świadomie (grupa C1, decyzja właściciela): pierwsze
                     zapewniało o czymś, czego nie da się sprawdzić, drugie
                     uspokajało zamiast zapraszać. W ich miejsce stoi to, co
                     da się zrobić i co z tego wynika. --}}
                {{-- Akapit skrócony do jednego zdania, żeby hasło i akcja mieściły
                     się na pierwszym ekranie przy 320 px i skali tekstu 100%
                     (wymóg: `STRONA-WWW.md:121`). Przy 320 px poprzednia wersja
                     zajmowała 8 wierszy i 273 px — sama spychała przycisk poniżej
                     krawędzi okna. Skrócenie było jedyną drogą, która nie zmniejsza
                     pisma: 22 px akapitu i 48 px celu dotknięcia zostają nietknięte.

                     Zostaje zdanie, które `docs/brand/GLOS_MARKI.md` §C1
                     (wiersz 418) wymienia jako wzorcowe (✅) i które mówi,
                     CO ZROBIĆ. Wypadł opis „to
                     miejsce dla ludzi, którzy gotują codziennie — w swojej kuchni,
                     z tego, co jest": tę samą rzecz mówi już nadtytuł „Gotujemy po
                     swojemu." i opis strony w <head>. --}}
                <p class="text-lead hero-lead miara">
                    Wrzuć zdjęcie i kilka słów, a pokażesz je komuś, kto dziś też gotował.
                </p>
                <div class="hero-akcje">
                    {{-- `btn-napis` NIE JEST OZDOBNIKIEM — patrz issue #353 i komentarz
                         przy `.btn-napis` w `resources/css/tokens.css`. `.btn` jest
                         `inline-flex`, więc bez tego `<span>` napis to trzy elementy
                         flex („Zostań ", nazwa, „ — bez opłat i bez reklam"), każdy
                         zawijany osobno i łamany w środku wyrazu.

                         NAPIS JEST DECYZJĄ WŁAŚCICIELA, nie propozycją: „to darmowe"
                         brzmiało sprzedażowo, a „za darmo, na zawsze" obiecywałoby
                         przyszłość bez gwarancji. „Bez opłat i bez reklam" mówi
                         o stanie dzisiejszym i o zobowiązaniu, które ma pokrycie
                         w decyzji o monetyzacji (dobrowolna zbiórka na hosting,
                         nigdy reklamy i nigdy płatny dostęp do cudzych przepisów).
                         Uzasadnienie: `docs/brand/GLOS_MARKI.md` §6. --}}
                    <a class="btn btn-primary btn-duzy" href="{{ route('register') }}"><span class="btn-napis">Zostań <x-kuking-word forma="iem" /> — bez opłat i bez reklam</span></a>
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
                 Widok pokazuje od jednego do czterech dostępnych kafli.
                 CSS dopasowuje siatkę do ich liczby; bez zdjęć hero ma jedną
                 kolumnę. Dobór i sprawdzenie widoczności należą do HeroKolaz.

                 DLACZEGO TO JEST OZDOBNIK (`alt=""` + `aria-hidden`)
                 Rozstrzygnięcie, nie odruch. Te zdjęcia nie są odnośnikiem,
                 nie mają podpisu przy sobie, nie da się z nich nigdzie przejść
                 i nie niosą ani jednej informacji, której nie ma w zdaniu
                 obok („miejsce dla ludzi, którzy gotują codziennie — w swojej
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
                 starcza także przy gęstszym ekranie.

                 `loading="lazy"` stało tu z uzasadnieniem, że poniżej 64rem
                 kolaż jest ukryty, więc przeglądarka nie pobiera ani jednego
                 z tych plików. TO UZASADNIENIE JEST NIEPRAWDZIWE od
                 13 września 2026: `marka-ekrany.css` (`@layer marka`) ustawia
                 `.hero-kolaz-blok { display: block }` bezwarunkowo i bije
                 regułę ukrywającą z `strony-publiczne.css`. Zmierzone
                 20 września 2026 przy oknie 320×568 na liście żądań
                 sieciowych: telefon pobiera WSZYSTKIE CZTERY kafle.
                 Pełny opis, tabela pomiarów i kontrola ujemna stoją przy
                 regule w `resources/css/strony-publiczne.css`.

                 Atrybut zostaje, ale NIE DLATEGO, że coś odsuwa: kolaż ma
                 górę na 891 px przy oknie 320×568, czyli poniżej pierwszego
                 ekranu, a mimo to wszystkie cztery pliki są pobierane BEZ
                 PRZEWIJANIA, przy pierwszym wczytaniu. Chromium ładuje
                 `lazy` z zapasem odległości liczonym w setkach pikseli i ten
                 zapas obejmuje tu cały kolaż. Ile ten atrybut naprawdę
                 oszczędza na tej stronie — nie zmierzono; dopóki nie zostanie
                 zmierzone, nie należy pisać, że oszczędza cokolwiek.

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

    <section class="pas landing-opowiesc" id="jak-dziala" aria-label="Jak działa">
        <div class="pas-wnetrze">
            <p class="nadtytul">Od Twojej kuchni do wspólnego stołu</p>
            <h2 class="landing-opowiesc-tytul">Zdjęcie. Kilka słów. <span>I rozmowa przy okazji.</span></h2>
            <ol class="landing-kroki">
                <li>
                    <p class="landing-krok-numer" aria-label="Krok 1">01</p>
                    <h3>Robisz zdjęcie</h3>
                    <p>Telefonem, prosto z garnka. Nie musi być z okładki.</p>
                    {{-- #1289: landing widzi tylko gość, a `posts.create`, `recipes.create`
                         i `collections.index` stoją za logowaniem. Odnośnik z opowieści
                         o funkcji prowadzi więc do publicznej treści, nie do bramki. --}}
                    <a href="{{ route('help') }}#dodawanie-zdjecia">Zobacz, jak dodać zdjęcie</a>
                </li>
                <li>
                    <p class="landing-krok-numer" aria-label="Krok 2">02</p>
                    <h3>Piszesz kilka słów</h3>
                    <p>Co to jest i z czego. A jeśli chcesz przekazać cały przepis — jest na niego miejsce.</p>
                    <a href="{{ route('search', ['sekcja' => 'przepisy']) }}">Zobacz przepisy innych</a>
                </li>
                <li>
                    <p class="landing-krok-numer" aria-label="Krok 3">03</p>
                    <h3>Ktoś odpowiada</h3>
                    <p>Pyta, dzieli się swoim sposobem albo pokazuje, jak wyszło u niego.</p>
                    <a href="{{ route('discover') }}">Zobacz, co gotują inni</a>
                </li>
            </ol>
        </div>
    </section>

    <section class="pas landing-wykonanie" id="ugotowalem">
        <div class="pas-wnetrze">
            @php($zdjecieUgotowalem = $kolaz->first())
            <div @class(['landing-wykonanie-karta', 'blok-ciemny', 'landing-wykonanie-bez-zdjecia' => $zdjecieUgotowalem === null])>
                <div class="landing-wykonanie-tekst">
                    <p class="nadtytul">Ugotowałem</p>
                    <h2>Twój przepis. <span>Czyjś dobry obiad.</span></h2>
                    <p>Pod każdym przepisem jest przycisk „Ugotowałem”. Dodajesz zdjęcie wykonania, a autor dowiaduje się, że przepis trafił do kolejnej kuchni.</p>
                    <p>Przy przepisie można zobaczyć zdjęcia od osób, które go przygotowały.</p>
                    <a href="{{ route('search', ['sekcja' => 'przepisy']) }}">Znajdź przepis dla siebie</a>
                </div>
                @if($zdjecieUgotowalem !== null)
                    <figure class="landing-wykonanie-zdjecie">
                        <img src="{{ $zdjecieUgotowalem['media']->url('feed') }}" alt=""
                             width="{{ $zdjecieUgotowalem['media']->width('feed') ?? 960 }}"
                             height="{{ $zdjecieUgotowalem['media']->height('feed') ?? 960 }}"
                             loading="lazy" decoding="async">
                        <figcaption>Zdjęcie: {{ $zdjecieUgotowalem['autor']->displayName() }}.</figcaption>
                    </figure>
                @endif
            </div>
        </div>
    </section>

    {{-- Prawdziwe osoby i dania pozostają po wprowadzeniu do funkcji. --}}
    <section class="pas pas--kreska-gora">
        <div class="pas-wnetrze">
            <x-kuking-board :people="$board['people']" :posts="$board['posts']" :notes="$board['notes']" :graSlowem="false" />
        </div>
    </section>

    {{-- 5. ŚWIEŻO Z KUKING ---------------------------------------------- --}}
    <section class="pas pas--kreska-gora">
        <div class="pas-wnetrze">
            <h2 class="text-title-lg">Świeżo z <x-kuking-word /></h2>
            <p class="text-lead miara">To, co ludzie ugotowali w ostatnich dniach.</p>

            @if($posts->count() === 0)
                <div class="odstep-nad">
                    <x-empty-state title="Kuking dopiero się zaczyna">
                        Jeszcze nic tu nie ma. Jeśli lubisz gotować, możesz być jedną z pierwszych osób,
                        które tu coś pokażą.
                    </x-empty-state>
                </div>
            @else
                {{-- #560: kolejność DOM i Tab pozostaje chronologiczna. Na szerokim
                     ekranie skrypt układa parzyste i nieparzyste karty niezależnie,
                     więc krótka karta nie czeka na wysoką sąsiadkę. Bez skryptu
                     działa zwykła siatka, a na telefonie jedna kolumna (D-216). --}}
                <div class="landing-wpisy-kolumna landing-wpisy-dwie odstep-nad">
                    @foreach($posts as $post)
                        <x-post-card :post="$post" />
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- 6. TWOJE DANE --------------------------------------------------- --}}
    <section class="pas marka-wlasnosc" aria-labelledby="wlasne-tresci-tytul">
        <div class="pas-wnetrze">
            <header class="marka-wlasnosc-naglowek">
                <p class="start-nadtytul">Twoja kuchnia. Twoje decyzje.</p>
                <h2 id="wlasne-tresci-tytul">Przepisy zostają Twoje.</h2>
                <p>Od zapisania rodzinnej receptury po zabranie własnych treści ze sobą.</p>
            </header>
            <div class="marka-wlasnosc-karty">
                <article>
                    <p class="marka-wlasnosc-etykieta">Mój zeszyt</p>
                    <h3>Na następny obiad</h3>
                    <p>Zbieraj przepisy i inspiracje w jednym miejscu. Układaj je w zeszyty, do których łatwo wrócisz.</p>
                </article>
                <article>
                    <p class="marka-wlasnosc-etykieta">Widoczność wpisu</p>
                    <h3>Ty wybierasz, kto zobaczy</h3>
                    <p>Przy każdym wpisie decydujesz, kto go widzi: wszyscy, tylko obserwujący albo tylko Ty.</p>
                    <a href="{{ route('help') }}#kto-widzi">Zobacz, kto może widzieć wpis</a>
                </article>
                <article>
                    <p class="marka-wlasnosc-etykieta">Własne treści</p>
                    <h3>Możesz je zabrać ze sobą</h3>
                    <p>
                        W każdej chwili możesz zamówić paczkę ze swoimi zdjęciami, wpisami
                        i przepisami — przygotujemy ją i damy znać, kiedy będzie do pobrania.
                        Otworzysz ją na swoim komputerze, także wtedy, gdyby <x-kuking-word />
                        kiedyś przestał istnieć.
                    </p>
                </article>
            </div>
            <div class="marka-wlasnosc-zasady">
                <h3>Bez opłat i bez reklam.</h3>
                <p>Prowadzimy to na własną rękę. Przeglądaj dania, pokazuj własne i rozmawiaj z innymi. Bez rankingu użytkowników i bez presji, żeby zaglądać codziennie.</p>
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
