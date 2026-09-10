@props(['ekran' => null])

{{--
    Pasek panelu — jeden nagłówek dla wszystkich ekranów `/admin/**`.

    PO CO TO POWSTAŁO
    Właściciel: „nie wiadomo, co jest normalną podstroną, a co adminową".
    Menu boczne to naprawia wydzieloną sekcją „Panel moderacji"
    (`components/layout.blade.php`) — ale na sam ekran panelu trafia się
    też z odnośnika w treści (np. z powiadomienia o zgłoszeniu), nie tylko
    z menu, więc sama strona musi też mówić, gdzie się jest. Sześć ekranów
    panelu miało każdy swój `<x-layout title="…">` i żadnego wspólnego
    paska — sześć miejsc, w których ten sam napis mógłby z czasem się
    rozjechać. Ten komponent jest tym jednym miejscem (ten sam wzorzec,
    co `<x-ustawienia-nawigacja>` dla ekranów ustawień).

    NAZWA „Panel moderacji" — to samo sformułowanie już żyje w kodzie
    (`pages/admin/wymagane_2fa.blade.php`: „Ten panel wymaga weryfikacji
    dwuetapowej", „Panel moderacji pokazuje zgłoszenia, ukryte treści
    i odwołania…") — dopisujemy się do istniejącego nazewnictwa, zamiast
    wymyślać czwarte słowo na to samo miejsce.

    KOLOR: `--color-accent`, ten sam token, którym menu boczne oznacza
    sekcję „Panel moderacji" (`.side-nav-moderacja-lista`) — jedno
    oznaczenie, widziane w dwóch miejscach, nie dwa różne.

    UŻYCIE: `<x-panel-moderacji ekran="Zgłoszenia" />` zaraz po otwarciu
    `<x-layout>`, przed `<h1>` ekranu. `<title>` strony dostaje ten sam
    dopisek osobno, przez `title="… — Panel moderacji"` przekazane
    do `<x-layout>` na każdym z tych ekranów.

    ====================================================================
    OZNACZENIE „P0 NIEPRZEJRZANE" (D-070) — DLACZEGO STOI WŁAŚNIE TUTAJ
    ====================================================================

    Bo ten pasek jest JEDYNYM elementem obecnym na KAŻDYM ekranie
    `/admin/**`, niezależnie od wybranej zakładki i filtra. Sprawa
    krytyczna (CSAM, groźba zagrażająca życiu, aktywny doxxing) nie może
    zależeć od tego, czy moderator ma otwartą tę zakładkę, na której leży —
    a leżeć może w czterech różnych widokach kolejki („Nowe", „W trakcie",
    „Wszystkie", oznaczenia automatu).

    NIE DA SIĘ TEGO ODKLIKNĄĆ: nie ma przycisku „ukryj", nie ma zapisu
    w sesji ani w `localStorage`, nie ma „przypomnij później". Liczba
    schodzi do zera trzema drogami i każda wymaga PODJĘCIA sprawy przez
    człowieka: rozstrzygnięciem, wzięciem do przeglądu (wygasa po ośmiu
    godzinach i alarm wraca) albo obniżeniem priorytetu z uzasadnieniem.
    Pełne uzasadnienie: `App\Domain\Moderation\PilneSprawy`.

    ZAPYTANIE, NIE CACHE — świadomie inaczej niż pięć liczników w menu
    bocznym (`KolejkiPanelu`). Nieodświeżony cache pokazałby tu „nic
    pilnego" przy zgłoszeniu sprzed dwóch minut, a to jest kłamstwo
    o najwyższej możliwej cenie. Rachunek jest w tamtej klasie.

    KOLOR: to jedyne miejsce w panelu z `--color-danger`. Reszta panelu
    świadomie go nie używa („kolejka moderacji jest miejscem pracy, nie
    awarią" — komentarz przy `.licznik-kolejki` w `app.css`) i ta reguła
    zostaje. Wyjątek jest tu uzasadniony właśnie tym, że jest wyjątkiem:
    ten pasek świeci się prawie nigdy, więc gdy się świeci, nie konkuruje
    z niczym o uwagę. Gdyby czerwień oznaczała zwykłą kolejkę, ten alarm
    zniknąłby w tle w ciągu tygodnia.
--}}
@php
    // Liczymy tylko dla moderatora — na `/admin/**` nikt inny nie wchodzi
    // (`EnsureUserIsModerator` oddaje 404), ale ten komponent jest zwykłym
    // Blade i nie ma prawa zakładać, kto go wywołał.
    $pilnych = auth()->user()?->isModerator() === true
        ? app(\App\Domain\Moderation\PilneSprawy::class)->ile()
        : 0;

    // Najstarszą sprawę czytamy WYŁĄCZNIE wtedy, gdy naprawdę jest co
    // pokazać. „P0 nieprzejrzane: 1" bez informacji, jak długo to leży, nie
    // mówi tego, co przy P0 jest najważniejsze: czy przyszło minutę temu,
    // czy przespaliśmy całą noc.
    $najstarszaPilna = $pilnych > 0
        ? app(\App\Domain\Moderation\PilneSprawy::class)->najstarsza()
        : null;
@endphp
<div class="panel-pasek">
    {{-- Bez `class="…"` na `<x-ikona>`: komponent ma już wpisane na sztywno
         `class="ikona"` w swoim znaczniku, a druga taka atrybucja z
         `$attributes` ląduje w HTML-u jako DRUGI, zduplikowany atrybut
         `class` — przeglądarka honoruje tylko pierwszy i po cichu pomija
         drugi (zmierzone `DOMDocument::getAttribute()`), więc taka klasa
         nigdy by się nie zastosowała. Kolor akcentu nadaje niżej sam
         `.panel-pasek` — ikona bierze go przez zwykłe dziedziczenie
         `color`/`currentColor`, tak samo jak w `.side-nav-moderacja-lista`. --}}
    <x-ikona nazwa="shield" :rozmiar="22" />
    <p class="panel-pasek-tekst">
        <span class="panel-pasek-nazwa">Panel moderacji</span>
        @if($ekran)
            <span class="panel-pasek-oddzielacz" aria-hidden="true">·</span>
            <span class="panel-pasek-ekran">{{ $ekran }}</span>
        @endif
    </p>
</div>

@if($pilnych > 0)
    {{-- `role="alert"` — czytnik ekranu ma to przeczytać od razu po wejściu
         na ekran, a nie dopiero wtedy, gdy ktoś dojedzie tu tabulatorem.
         Ikona NIE stoi sama: pod nią i obok niej jest pełny tekst
         (`AGENTS.md` §5). --}}
    <div class="alarm-pilne" role="alert">
        <p class="alarm-pilne-naglowek">
            <x-ikona nazwa="shield" :rozmiar="22" />
            <span>
                @if($pilnych === 1)
                    Jedna sprawa krytyczna (P0) czeka na przejrzenie
                @else
                    {{ $pilnych }} sprawy krytyczne (P0) czekają na przejrzenie
                @endif
            </span>
        </p>
        <p class="alarm-pilne-tresc">
            P0 to zgłoszenia dotyczące dzieci, groźby zagrażające życiu i ujawnienie
            czyichś danych. Podręcznik moderacji przewiduje dla nich reakcję natychmiast,
            poza kolejnością wszystkiego innego.
            @if($najstarszaPilna !== null)
                Najdłużej czeka sprawa <strong>{{ $najstarszaPilna->numer_sprawy }}</strong>,
                zgłoszona {{ \App\Support\Czas::data($najstarszaPilna->created_at, 'j F Y, H:i') }}.
            @endif
        </p>
        <p class="alarm-pilne-tresc">
            Tego oznaczenia nie da się zamknąć. Zniknie, gdy sprawa zostanie rozstrzygnięta,
            wzięta do przeglądu albo gdy obniżysz jej priorytet z uzasadnieniem.
        </p>
        <a class="btn btn-danger" href="{{ route('admin.reports', ['pilne' => 1]) }}">
            Pokaż sprawy pilne
        </a>
    </div>
@endif
