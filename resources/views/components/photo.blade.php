{{--
    Zdjęcie z wariantami.

    Zawsze podajemy width/height — bez tego strona „skacze” przy wczytywaniu
    (CLS), co przy powiększonym tekście jest szczególnie irytujące.
    Zdjęcie w innym stanie niż `ready` nie jest pokazywane wcale (AGENTS.md
    §7) — ale „nie pokazuj zdjęcia” i „nie pokazuj NIC” to dwie różne rzeczy.
    Ta druga gałąź niżej istnieje właśnie po to, żeby to rozróżnić.
--}}
@props(['media' => null, 'variant' => 'feed', 'priority' => false, 'class' => 'post-photo', 'zoom' => true])
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
         sizes="(min-width: 64rem) 720px, 100vw"
         alt="{{ $media->alt_text ?? '' }}"
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
        $proporcje = ($media->width && $media->height) ? $media->width.' / '.$media->height : '4 / 3';
        $padloNaDobre = $media->status === \App\Models\Media::STATUS_REJECTED;
    @endphp
    <div class="{{ $class }}"
         role="status"
         style="aspect-ratio:{{ $proporcje }}; display:flex; align-items:center; justify-content:center; text-align:center; padding:var(--spacing-4); border-radius:var(--radius-md);">
        @if($padloNaDobre)
            <p style="margin:0; font-weight:700; color:var(--color-danger-tint-ink);">
                @if($jestWlascicielem)
                    Nie udało się przygotować tego zdjęcia. Wpis możesz usunąć i dodać ponownie z innym zdjęciem.
                @else
                    Tego zdjęcia nie udało się przygotować.
                @endif
            </p>
        @else
            <p style="margin:0; font-weight:700; color:var(--color-ink-muted);">
                @if($jestWlascicielem)
                    Twoje zdjęcie się jeszcze przygotowuje. Nic nie zginęło — odśwież stronę za chwilę, żeby je zobaczyć.
                @else
                    To zdjęcie jeszcze się przygotowuje.
                @endif
            </p>
        @endif
    </div>
@endif
