@props(['user' => null, 'size' => 48])
@php
    $profile = $user?->profile;
    $media = $profile?->avatar;
    $initial = mb_strtoupper(mb_substr($profile?->display_name ?? '?', 0, 1));
@endphp
{{--
    Rozmiar idzie przez `data-rozmiar`, a nie przez `style` (issue #107):
    atrybut `style` jest jedyną rzeczą, która trzyma `unsafe-inline`
    w `style-src`, a nonce go nie obejmuje. Reguły dla wszystkich rozmiarów
    w użyciu (40–88 px) stoją w `app.css`; rozmiar spoza tej listy zostaje przy
    domyślnych 48 px z `.avatar` — awatar się zmniejszy, ale nie zniknie.
--}}
@if($media && $media->isReady())
    <img class="avatar" src="{{ $media->url('thumb') }}" alt=""
         width="{{ $size }}" height="{{ $size }}"
         data-rozmiar="{{ $size }}" loading="lazy">
@else
    {{-- Brak avatara to norma, nie błąd. Inicjał zamiast szarej sylwetki. --}}
    <span class="avatar" aria-hidden="true" data-rozmiar="{{ $size }}">{{ $initial }}</span>
@endif
