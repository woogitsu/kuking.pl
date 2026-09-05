{{--
    Zdjęcie z wariantami.

    Zawsze podajemy width/height — bez tego strona „skacze” przy wczytywaniu
    (CLS), co przy powiększonym tekście jest szczególnie irytujące.
    Zdjęcie w innym stanie niż `ready` nie jest pokazywane wcale.
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
@endif
