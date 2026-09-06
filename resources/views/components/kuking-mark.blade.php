{{--
    Znak „Uśmiech" wklejony wprost w HTML.

    DLACZEGO NIE <img src="icons/kuking-mark.svg">
    SVG załadowany przez <img> jest OSOBNYM dokumentem: nie widzi zmiennych
    CSS strony i nie dziedziczy `currentColor` — dostaje czerń. Wklejony
    wprost bierze kolor stąd, gdzie stoi, więc jeden kształt obsługuje
    tryb jasny, ciemny i wersję na kolorowym tle bez trzech plików.

    Plik `public/icons/kuking-mark.svg` zostaje dla favikony, service workera
    i zastępczego zdjęcia — tam osobny dokument jest jedyną możliwością
    i dlatego ma kolory wpisane wprost.

    DOMYŚLNIE ZNAK JEST NIEMY dla czytnika ekranu (`aria-hidden`). Prawie
    zawsze stoi obok napisu „KuKing.pl" i przeczytanie nazwy dwa razy to szum.
    Tam, gdzie stoi sam, podaj `:etykieta="'Kuking'"` — wtedy dostaje rolę
    obrazka i tytuł.
--}}
@props(['rozmiar' => 32, 'etykieta' => null])

<svg class="kuking-mark" viewBox="0 0 64 64" width="{{ $rozmiar }}" height="{{ $rozmiar }}"
     fill="none"
     @if($etykieta) role="img" aria-label="{{ $etykieta }}" @else aria-hidden="true" focusable="false" @endif
     {{ $attributes }}>
    @if($etykieta)<title>{{ $etykieta }}</title>@endif

    <path d="M18 22 L16 15 L24 19 L32 11 L40 19 L48 15 L46 22 C39 25 25 25 18 22 Z"
          fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
    <circle cx="16" cy="14" r="3.5" fill="currentColor"/>
    <circle cx="32" cy="9" r="3.5" fill="currentColor"/>
    <circle cx="48" cy="14" r="3.5" fill="currentColor"/>

    <path d="M17 29 H47 V38 C47 49 41 54 32 54 C23 54 17 49 17 38 Z" fill="currentColor"/>

    <path d="M17 32 C12 29 9 31 9 36 C9 42 13 44 18 42" stroke="currentColor"
          stroke-width="5" stroke-linecap="round"/>
    <path d="M47 32 C52 29 55 31 55 36 C55 42 51 44 46 42" stroke="currentColor"
          stroke-width="5" stroke-linecap="round"/>

    {{-- Uśmiech i połysk: kolor POWIERZCHNI, na której znak stoi, a nie biel
         na sztywno. Dzięki temu w trybie ciemnym linie są ciemne, a nie
         świecące — tak samo jak na mockupie, tylko z odwróconą paletą. --}}
    <path d="M24 43 C28 47 36 47 40 41" stroke="var(--color-surface-raised)"
          stroke-width="3.5" stroke-linecap="round"/>
    <path d="M18 28 C27 30 37 30 46 28" stroke="var(--color-surface-raised)"
          stroke-width="3" stroke-linecap="round"/>
</svg>
