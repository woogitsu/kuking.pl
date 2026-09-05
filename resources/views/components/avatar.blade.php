@props(['user' => null, 'size' => 48])
@php
    $profile = $user?->profile;
    $media = $profile?->avatar;
    $initial = mb_strtoupper(mb_substr($profile?->display_name ?? '?', 0, 1));
@endphp
@if($media && $media->isReady())
    <img class="avatar" src="{{ $media->url('thumb') }}" alt=""
         width="{{ $size }}" height="{{ $size }}"
         style="width:{{ $size }}px;height:{{ $size }}px;" loading="lazy">
@else
    {{-- Brak avatara to norma, nie błąd. Inicjał zamiast szarej sylwetki. --}}
    <span class="avatar" aria-hidden="true"
          style="width:{{ $size }}px;height:{{ $size }}px;font-size:{{ (int) round($size * 0.42) }}px;">{{ $initial }}</span>
@endif
