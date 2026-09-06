{{--
    Zdjęcie z wariantami.

    Zawsze podajemy width/height — bez tego strona „skacze” przy wczytywaniu
    (CLS), co przy powiększonym tekście jest szczególnie irytujące.
    Zdjęcie w innym stanie niż `ready` nie jest pokazywane wcale (AGENTS.md
    §7) — ale „nie pokazuj zdjęcia” i „nie pokazuj NIC” to dwie różne rzeczy.
    Ta druga gałąź niżej istnieje właśnie po to, żeby to rozróżnić.
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
@if($media && $media->isReady())
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
    <img class="{{ $class }}"
         src="{{ $media->url($variant) }}"
         srcset="{{ $media->url('thumb') }} 320w, {{ $media->url('feed') }} 960w, {{ $media->url('large') }} 1600w"
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
        AUDYT A2 — zdjęcie istnieje, ale NIE jest `ready`.

        Wcześniej ta gałąź w ogóle nie istniała: w miejscu zdjęcia zostawało
        puste miejsce, bez śladu wyjaśnienia. Dla autora, który przed chwilą
        kliknął „Opublikuj”, wygląda to jak porażka publikacji, nie jak
        „chwilę potrwa” — i taka osoba próbuje wysłać wpis jeszcze raz albo
        rezygnuje (patrz PrzygotowywanieZdjeciaWpisuTest).

        MIEJSCE JEST ŚWIADOMIE REZERWOWANE, przez `aspect-ratio` policzony
        z RZECZYWISTYCH wymiarów zdjęcia. `width`/`height` w tabeli `media`
        są znane OD RAZU po wgraniu — StoreUploadedImage czyta je z
        `getimagesize`, zanim zadanie w tle w ogóle ruszy — więc ramka ma
        naprawdę taki kształt, jaki będzie miało gotowe zdjęcie. Osoba,
        która niecierpliwie odświeża stronę ręcznie (naturalne zachowanie
        w tym oknie oczekiwania), nie zobaczy karty, która nagle zmienia
        wysokość, kiedy zdjęcie się w końcu pojawi.

        CZEGO NIE WYBRANO: automatycznego odświeżania (meta refresh albo
        JavaScript). Techniczne „skakanie” układu (CLS) przy pełnym
        przeładowaniu strony i tak nie istnieje — to nie jest podmiana
        w locie, tylko nowa strona od zera. A osoba 50+, która W TEJ CHWILI
        czyta wpis, nie powinna dostać przeładowania strony pod palcami bez
        pytania — to jest dokładnie ten rodzaj „magii” interfejsu, przed
        którym ostrzega docs/UX_50_PLUS.md. Odświeżenie zostaje w rękach
        człowieka: przycisk odświeżania przeglądarki już istnieje i działa
        bez JavaScriptu.

        TREŚĆ ZALEŻY OD DWÓCH RZECZY: czy to WŁAŚCICIEL zdjęcia patrzy na
        swój własny wpis (dostaje osobiste zapewnienie „nic nie zginęło"),
        i czy przetworzenie jeszcze trwa, czy już padło na dobre
        (`rejected` — zadanie wyczerpało próby, patrz ProcessUploadedImage).
        Wpis z pustym miejscem po zdjęciu w nieskończoność byłby gorszy niż
        wpis, który wprost mówi, że się nie udało.
    --}}
    @php
        $jestWlascicielem = auth()->id() === $media->owner_id;
        $padloNaDobre = $media->status === \App\Models\Media::STATUS_REJECTED;

        /*
         * PROPORCJE Z KLASY, NIE Z DOKŁADNYCH WYMIARÓW PLIKU (issue #107).
         *
         * Wcześniej szło tu `aspect-ratio:{szerokość} / {wysokość}` w atrybucie
         * `style` — jedynej rzeczy, która trzyma `unsafe-inline` w `style-src`;
         * nonce atrybutów nie obejmuje. Dokładnej liczby nie da się zapisać
         * klasą, więc zaokrąglamy do czterech kształtów.
         *
         * Kosztem jest kilka procent różnicy między ramką a zdjęciem, które
         * się w niej pojawi. To jest do przyjęcia AKURAT TUTAJ: ten prostokąt
         * pokazuje się wyłącznie zanim zdjęcie będzie gotowe, a komentarz
         * wyżej tłumaczy, że strona i tak przeładowuje się w całości, więc nie
         * ma podmiany w locie, przy której ta różnica byłaby widoczna jako
         * skok układu.
         */
        $stosunek = ($media->width && $media->height) ? $media->width / $media->height : 4 / 3;

        $ksztalt = match (true) {
            $stosunek >= 1.6 => ' photo-placeholder-panorama',
            $stosunek >= 1.15 => '',            // domyślne 4 / 3
            $stosunek >= 0.9 => ' photo-placeholder-kwadrat',
            default => ' photo-placeholder-pion',
        };
    @endphp
    <div class="{{ $class }} photo-placeholder{{ $ksztalt }}{{ $padloNaDobre ? ' photo-placeholder-blad' : '' }}"
         role="status">
        @if($padloNaDobre)
            <p>
                @if($jestWlascicielem)
                    Nie udało się przygotować tego zdjęcia. Wpis możesz usunąć i dodać ponownie z innym zdjęciem.
                @else
                    Tego zdjęcia nie udało się przygotować.
                @endif
            </p>
        @else
            <p>
                @if($jestWlascicielem)
                    Twoje zdjęcie się jeszcze przygotowuje. Nic nie zginęło — odśwież stronę za chwilę, żeby je zobaczyć.
                @else
                    To zdjęcie jeszcze się przygotowuje.
                @endif
            </p>
        @endif
    </div>
@endif
