{{--
    Zdjęcie z wariantami.

    Zawsze podajemy width/height — bez tego strona „skacze” przy wczytywaniu
    (CLS), co przy powiększonym tekście jest szczególnie irytujące.
    Zdjęcie w innym stanie niż `ready` nie jest pokazywane wcale.
--}}
@props(['media' => null, 'variant' => 'feed', 'priority' => false, 'class' => 'post-photo'])
@if($media && $media->isReady())
    <img class="{{ $class }}"
         src="{{ $media->url($variant) }}"
         srcset="{{ $media->url('thumb') }} 320w, {{ $media->url('feed') }} 960w, {{ $media->url('large') }} 1600w"
         sizes="(min-width: 64rem) 720px, 100vw"
         alt="{{ $media->alt_text ?? '' }}"
         width="{{ $media->width($variant) }}"
         height="{{ $media->height($variant) }}"
         @if($priority) fetchpriority="high" @else loading="lazy" decoding="async" @endif>
@endif
