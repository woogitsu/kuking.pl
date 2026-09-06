{{--
    Zestaw ikon z UI kitu v2 (etap B).

    DLACZEGO SVG, A NIE EMOJI
    Nawigacja używała emoji: 🏠 🔍 ➕ 📒 👤 🍲 ⚙️. Wyglądają one INACZEJ
    na każdym systemie — na Androidzie, na iPhonie i w Windowsie to trzy
    różne obrazki tego samego znaku. Część z nich jest w dodatku wyraźnie
    zabawkowa, a `docs/BRAND.md` i badania nad interfejsami dla osób starszych
    mówią o tym wprost: „toy-like" jest odbierane jako infantylizujące.

    Emoji ma jeszcze dwie wady, których nie widać przy pobieżnym spojrzeniu:
    nie przyjmuje koloru z motywu (w trybie ciemnym zostaje kolorową plamą)
    i przy dużej skali tekstu skaluje się inaczej niż napis obok.

    IKONA NIGDY NIE JEST SAMA (AGENTS.md, docs/UX_50_PLUS.md)
    Ten komponent jest z założenia NIEMY dla czytnika ekranu: `aria-hidden`
    i `focusable="false"`. Nie da się go użyć jako jedynego opisu akcji,
    bo nie da się go opisać. Obok zawsze stoi tekst.

    Kształty są z paczki właściciela, w jednym miejscu: `stroke` bierze kolor
    z otoczenia, więc jedna ikona obsługuje oba motywy i stan „bieżąca pozycja".

    Użycie:  <x-ikona nazwa="home" />
             <x-ikona nazwa="bell" :rozmiar="20" />
--}}
@props(['nazwa', 'rozmiar' => 24])

@php
    /**
     * Ikony rysowane KONTUREM (stroke) — domyślne zachowanie.
     * `more` jest wyjątkiem: trzy kropki muszą być wypełnione, inaczej
     * przy grubości linii 1.8 zlewają się w kółka bez środka.
     */
    $ksztalty = [
        'home' => '<path d="M3 11.5 12 4l9 7.5v8a1.5 1.5 0 0 1-1.5 1.5H15v-6H9v6H4.5A1.5 1.5 0 0 1 3 19.5z"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m15.5 15.5 5 5"/>',
        'plus' => '<path d="M12 4v16M4 12h16"/>',
        'book' => '<path d="M5 4.5A2.5 2.5 0 0 1 7.5 2H19v18H7.5A2.5 2.5 0 0 0 5 22z"/><path d="M5 4.5v15M9 6h6"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4.5 21c.7-5 3.2-7 7.5-7s6.8 2 7.5 7"/>',
        'bell' => '<path d="M6 9a6 6 0 0 1 12 0c0 6 2.5 6 2.5 8H3.5C3.5 15 6 15 6 9Z"/><path d="M9.5 20a3 3 0 0 0 5 0"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19 13.5v-3l2-1.5-2-3-2.5 1A8 8 0 0 0 14 5.5L13.5 3h-3L10 5.5A8 8 0 0 0 7.5 7L5 6 3 9l2 1.5v3L3 15l2 3 2.5-1A8 8 0 0 0 10 18.5l.5 2.5h3l.5-2.5a8 8 0 0 0 2.5-1.5l2.5 1 2-3z"/>',
        'chat' => '<path d="M21 11.5a8.5 8.5 0 0 1-9 8.5 10 10 0 0 1-4-.9L3 21l1.8-4A8 8 0 1 1 21 11.5Z"/>',
        'save' => '<path d="M6 3h12v18l-6-4-6 4z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v6l4 2"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 20c.5-4 2.5-6 6-6s5.5 2 6 6M15 15c3.5 0 5 1.7 5.5 5"/>',
        'chef' => '<path d="M7 10a4 4 0 1 1 1-7 4.5 4.5 0 0 1 8 0 4 4 0 1 1 1 7v7H7z"/><path d="M7 14h10M9 20h6"/>',
        'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m5 18 5-5 3 3 2-2 4 4"/>',
        'more' => '<circle cx="5" cy="12" r="1.5" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1.5" fill="currentColor" stroke="none"/>',
        'filter' => '<path d="M4 6h16M7 12h10M10 18h4"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'chevron' => '<path d="m9 6 6 6-6 6"/>',
        'shield' => '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/>',
        'pin' => '<path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
    ];

    // Nieznana nazwa nie może po cichu wyrenderować pustego kwadratu —
    // wtedy zostaje sam napis, a nikt nie zauważy, że ikony brakuje.
    $ksztalt = $ksztalty[$nazwa] ?? null;
@endphp

@if($ksztalt === null)
    {{-- Jawny ślad w kodzie strony zamiast cichej pustki. --}}
    <!-- brak ikony o nazwie "{{ $nazwa }}" — patrz resources/views/components/ikona.blade.php -->
@else
    <svg class="ikona" width="{{ $rozmiar }}" height="{{ $rozmiar }}" viewBox="0 0 24 24"
         fill="none" stroke="currentColor" stroke-width="1.8"
         stroke-linecap="round" stroke-linejoin="round"
         aria-hidden="true" focusable="false" {{ $attributes }}>{!! $ksztalt !!}</svg>
@endif
