{{--
    Zdjęcie z wariantami.

    Zawsze podajemy width/height — bez tego strona „skacze” przy wczytywaniu
    (CLS), co przy powiększonym tekście jest szczególnie irytujące.

    CO DECYDUJE O POKAZANIU ZDJĘCIA (issue #430, zmiana z 12 września 2026)
    Nie status wiersza, tylko to, czy istnieje już WARIANT — czyli plik, który
    wyszedł z naszego kodera, a więc bez EXIF-u. Oryginał nie jest wariantem
    i tą drogą nie wychodzi nigdy (patrz docblock `App\Models\Media`).

    Wcześniej stał tu `isReady()` i to był powód usterki: wgranie zdjęcia
    i publikacja wpisu to JEDNO żądanie, więc w chwili pierwszego renderu
    strony wpisu zadanie w tle nie mogło było jeszcze niczego policzyć.
    Autorka dostawała napis zamiast własnego obiadu — a właściciel serwisu
    o tym wprost: „Starzy ludzie nie czytają i będzie panika co się stało".

    Dziś `StoreUploadedImage` robi wariant `podglad` synchronicznie, więc ta
    gałąź prawie zawsze ma co pokazać. Gałąź zastępcza niżej ZOSTAJE, bo
    zostały sytuacje, w których wariantu naprawdę nie ma: zdjęcie ponad
    progiem megapikseli (`kuking.media.podglad.max_megapixels`), uszkodzony
    plik, przetwarzanie, które padło. „Nie pokazuj zdjęcia” i „nie pokazuj
    NIC” to dalej dwie różne rzeczy.
--}}
{{--
    `alt` i `sizes` (issue #92).

    `alt` — tekst alternatywny nie jest przy publikacji wymagany (to by
    dokładało pracę w momencie, w którym chcemy, żeby człowiek po prostu
    wrzucił zdjęcie), więc `alt_text` bywa pusty. Puste `alt` jest poprawne
    dla ozdobnika, ale zdjęcie w karuzeli JEST treścią i musi mieć jakikolwiek
    opis — choćby „Zdjęcie 2 z 4 w tym wpisie", które przynajmniej mówi,
    gdzie się jest. Ta prop pozwala podać taki zastępczy opis.

    `sizes` — w kolażu zdjęcie zajmuje pół szerokości karty, nie całą.
    Bez tego przeglądarka pobierałaby wariant dwa razy za duży dla każdego
    pola siatki, czyli sześć razy przy sześciu zdjęciach.
--}}
@props([
    'media' => null,
    'variant' => 'feed',
    'priority' => false,
    'class' => 'post-photo',
    'zoom' => true,
    'alt' => null,
    'sizes' => '(min-width: 64rem) 720px, 100vw',
])
@if($media && $media->maWariantDoPokazania($variant))
    @if($zoom)
        {{--
            Powiększanie zdjęcia.

            Link, nie przycisk z samym JavaScriptem. Bez skryptu kliknięcie
            otwiera duży wariant na osobnej stronie — czyli działa. Ze skryptem
            otwiera się nakładka, a przeglądarka nie opuszcza feedu.

            `aria-label` mówi, CO SIĘ STANIE, bo sam tekst alternatywny zdjęcia
            tego nie zdradza. Osoba czytająca ekranem usłyszy „Powiększ zdjęcie:
            rosół w garnku”, a nie samo „rosół w garnku”.

            Przy `zoom => false` (np. miniatura w karcie przepisu, która jest
            już linkiem do przepisu) nie owijamy niczym — zagnieżdżone `<a>`
            to nieprawidłowy HTML i psuje obsługę klawiaturą.
        --}}
        <a class="photo-zoom"
           href="{{ $media->url('large') }}"
           data-powieksz
           data-alt="{{ $media->alt_text ?? '' }}"
           aria-label="Powiększ zdjęcie{{ $media->alt_text ? ': '.$media->alt_text : '' }}">
    @endif
    @php
        /*
         * `srcset` Z PRAWDZIWYCH SZEROKOŚCI, nie z maksimów konfiguracji
         * (audyt zewnętrzny T30).
         *
         * Deskryptory były wpisane na sztywno: `320w, 960w, 1600w`, czyli
         * MAKSYMALNE krawędzie z `config('kuking.media.variants')`. Ale
         * `ProcessUploadedImage` skaluje przez `scaleDown()`, które NIGDY
         * NIE POWIĘKSZA — i to jest świadoma decyzja („małe zdjęcie zostaje
         * małe, zamiast być rozmyte na siłę").
         *
         * Zmierzone: dla zdjęcia 400×300 wszystkie trzy warianty mają
         * najwyżej 400 px, a `srcset` twierdził, że jeden ma 1600. To psuje
         * dokładnie ten mechanizm, dla którego `srcset` istnieje:
         * przeglądarka widzi „1600w", pobiera przy szerokim widoku NAJWIĘKSZY
         * z trzech plików, dostaje 400 px i rozciąga je. Użytkownik płaci
         * transferem za rozmyte zdjęcie.
         *
         * Prawdziwe szerokości leżą w `metadata.variants[*].width`, a
         * `Media::width()` je zwraca — ten sam komponent używał tej metody
         * w atrybucie `width` obok `srcset`, który mówił co innego o tym
         * samym pliku.
         *
         * DEDUPLIKACJA po szerokości: przy małym zdjęciu `feed` i `large`
         * mają tę samą szerokość, a dwa kandydaty o identycznym deskryptorze
         * nie dają przeglądarce żadnego wyboru — zostaje pierwszy, mniejszy
         * plik. Bierzemy więc jeden wariant na szerokość, od najmniejszego.
         */
        $kandydaci = [];

        /*
         * `podglad` NA LIŚCIE, I TO NIE TYLKO NA CZAS CZEKANIA (issue #430).
         * Wariant 640 px zostaje w metadanych na stałe, więc jest uczciwym
         * kandydatem między `thumb` (320) a `feed` (960) — na telefonie
         * o zwykłej gęstości pikseli przeglądarka pobierze 61,5 kB zamiast
         * 173,2 kB. Kolejność w tej pętli nie ustala niczego poza tym, który
         * plik wygrywa przy równej szerokości: sortuje niżej `ksort`.
         *
         * `maWariant()`, NIE samo `width()` — pytanie musi być DOSŁOWNE.
         * `width()` (jak `url()`) podstawia wariant zastępczy, więc zanim
         * zadanie w tle policzy resztę, wszystkie cztery nazwy wskazywałyby
         * na jeden plik `podglad`. Przeglądarka dostałaby cztery kandydatury
         * bez żadnego wyboru, a deskryptory kłamałyby o szerokości —
         * dokładnie ta usterka, którą naprawił audyt T30 niżej.
         */
        foreach (['thumb', 'podglad', 'feed', 'large'] as $nazwaWariantu) {
            if (! $media->maWariant($nazwaWariantu)) {
                continue;
            }

            $szerokoscWariantu = $media->width($nazwaWariantu);

            if ($szerokoscWariantu === null || isset($kandydaci[$szerokoscWariantu])) {
                continue;
            }

            $kandydaci[$szerokoscWariantu] = $media->url($nazwaWariantu).' '.$szerokoscWariantu.'w';
        }

        ksort($kandydaci);

        $srcset = implode(', ', $kandydaci);
    @endphp
    <img class="{{ $class }}"
         src="{{ $media->url($variant) }}"
         srcset="{{ $srcset }}"
         sizes="{{ $sizes }}"
         alt="{{ $alt ?: ($media->alt_text ?? '') }}"
         width="{{ $media->width($variant) }}"
         height="{{ $media->height($variant) }}"
         @if($priority) fetchpriority="high" @else loading="lazy" decoding="async" @endif>
    @if($zoom)
        </a>
    @endif
@elseif($media)
    {{--
        MIEJSCE ZDJĘCIA, KTÓREGO JESZCZE (ALBO JUŻ) NIE MA.

        Po #430 ta gałąź jest WYJĄTKIEM, nie regułą: zdjęcie z telefonu ma
        wariant `podglad` od razu po wgraniu i idzie gałęzią wyżej. Tu trafia
        tylko to, czego naprawdę nie da się pokazać — zdjęcie ponad progiem
        `kuking.media.podglad.max_megapixels` (czeka na workera), plik, na
        którym koder padł, i stare wiersze sprzed tej zmiany.

        DLACZEGO TO W OGÓLE ISTNIEJE, ZAMIAST PUSTKI (audyt A2)
        Dla autora, który przed chwilą kliknął „Opublikuj”, puste miejsce
        wygląda jak porażka publikacji, nie jak „chwilę potrwa” — i taka
        osoba próbuje wysłać wpis jeszcze raz albo rezygnuje.

        CZEGO NIE WYBRANO: automatycznego odświeżania (meta refresh albo
        JavaScript). Osoba 50+, która W TEJ CHWILI czyta wpis, nie powinna
        dostać przeładowania strony pod palcami bez pytania — to jest ten
        rodzaj „magii” interfejsu, przed którym ostrzega docs/UX_50_PLUS.md.
        Odświeżenie zostaje w rękach człowieka i działa bez JavaScriptu.

        --- CO ZMIENIŁO SIĘ TU 12 WRZEŚNIA 2026 (issue #432) ---

        1. BLOK IDZIE NA CAŁĄ SZEROKOŚĆ. Zgłoszenie właściciela: „powinno być
           na całą szerokość a nie po lewej stronie”. Blok dostawał szerokość
           od `.post-photo`, ale `display: flex` robił z akapitu element
           elastyczny, który kurczył się do treści — przy ~390 px zdanie łamało
           się na osiem wierszy, a kropka po „przygotowuje” wypadała na
           początku wiersza, oderwana od zdania. Dla kogoś, kto czyta wolno,
           jest to nieczytelne — a to jest komunikat, który ma uspokajać.

        2. DWA KRÓTKIE ZDANIA ZAMIAST JEDNEGO DŁUGIEGO. To jest właściwa
           naprawa osieroconej kropki, nie sama szerokość: znak interpunkcyjny
           nie ma jak zostać sam na początku wiersza, jeśli kończy zdanie,
           które mieści się w jednym wierszu. Nie polegamy tu wyłącznie na
           `text-wrap: pretty` — to jest podpowiedź dla przeglądarki, a nie
           gwarancja, i starsze przeglądarki jej nie znają.

        3. WYSOKOŚĆ NIE UDAJE ZDJĘCIA. Wcześniej blok rezerwował miejsce przez
           `aspect-ratio` policzony z wymiarów pliku. Po #430 to przestało mieć
           sens: w większości przypadków, które tu jeszcze docierają, zdjęcia
           nie będzie WCALE (koder padł), więc rezerwowany prostokąt to pół
           ekranu pustej szarości pod komunikatem, którego nikt przez to nie
           zauważa. Blok jest dziś wysoki na tyle, na ile ma treści.
    --}}
    @php
        $jestWlascicielem = auth()->id() === $media->owner_id;
        $padloNaDobre = $media->status === \App\Models\Media::STATUS_REJECTED;
    @endphp
    <div class="{{ $class }} photo-placeholder{{ $padloNaDobre ? ' photo-placeholder-blad' : '' }}"
         role="status">
        @if($padloNaDobre)
            <p class="photo-placeholder-zdanie">
                @if($jestWlascicielem)
                    Nie udało się przygotować tego zdjęcia.
                @else
                    Tego zdjęcia nie udało się przygotować.
                @endif
            </p>
            @if($jestWlascicielem)
                <p class="photo-placeholder-zdanie">Wpis możesz usunąć i dodać ponownie z innym zdjęciem.</p>
            @endif
        @else
            <p class="photo-placeholder-zdanie">
                @if($jestWlascicielem)
                    Twoje zdjęcie się jeszcze przygotowuje.
                @else
                    To zdjęcie jeszcze się przygotowuje.
                @endif
            </p>
            @if($jestWlascicielem)
                <p class="photo-placeholder-zdanie">Nic nie zginęło — odśwież stronę za chwilę, żeby je zobaczyć.</p>
            @endif
        @endif
    </div>
@endif
