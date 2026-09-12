@props(['user' => null, 'size' => 48])
@php
    $profile = $user?->profile;
    $initial = mb_strtoupper(mb_substr($profile?->display_name ?? '?', 0, 1));

    /*
        PYTAMY O WARIANT, NIE O STATUS WIERSZA (#448).

        Stało tu `$media->isReady()`, czyli pytanie o STAN WIERSZA `media`.
        Po wgraniu zdjęcia profilowego wiersz jest jeszcze `pending`, więc
        człowiek widział w miejscu swojej twarzy inicjał — także wtedy, gdy
        plik nadający się do pokazania już istniał. Zdjęcie profilowe wgrywa
        się RAZ, na początku, i jest to pierwszy moment, w którym ktoś
        sprawdza, czy „to działa".

        Rozstrzyga `Profile::zdjecieDoPokazania()`, a pod nim
        `Media::wariantDoSerwowania()` — jedno miejsce w serwisie, które wie,
        który PLIK idzie do przeglądarki. Oryginał wgrany przez człowieka nie
        jest wariantem i tą drogą nie przejdzie; w jego EXIF-ie siedzi
        lokalizacja kuchni.
    */
    $media = $profile?->zdjecieDoPokazania();
@endphp
{{--
    Rozmiar idzie przez `data-rozmiar`, a nie przez `style` (issue #107):
    atrybut `style` jest jedyną rzeczą, która trzyma `unsafe-inline`
    w `style-src`, a nonce go nie obejmuje. Reguły dla wszystkich rozmiarów
    w użyciu (40–128 px) stoją w `app.css`; rozmiar spoza tej listy zostaje przy
    domyślnych 48 px z `.avatar` — awatar się zmniejszy, ale nie zniknie.
--}}
@if($media)
    <img class="avatar" src="{{ $media->url('thumb') }}" alt=""
         width="{{ $size }}" height="{{ $size }}"
         data-rozmiar="{{ $size }}" loading="lazy">
@else
    {{-- Brak avatara to norma, nie błąd. Inicjał zamiast szarej sylwetki. --}}
    <span class="avatar" aria-hidden="true" data-rozmiar="{{ $size }}">{{ $initial }}</span>
@endif
