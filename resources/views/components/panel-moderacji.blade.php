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

    Port #581: nazwę trybu i tarczę zachowujemy, a neutralna powierzchnia
    i typografia łączą pasek z nową ramą panelu. Czerwień oznacza wybór
    w nawigacji; alarmy zachowują osobne znaczenie.

    UŻYCIE: `<x-panel-moderacji ekran="Zgłoszenia" />` zaraz po otwarciu
    `<x-layout>`, przed `<h1>` ekranu. `<title>` strony dostaje ten sam
    dopisek osobno, przez `title="… — Panel moderacji"` przekazane
    do `<x-layout>` na każdym z tych ekranów.
--}}
<div class="panel-pasek marka-panel-naglowek">
    {{-- Bez `class="…"` na `<x-ikona>`: komponent ma już wpisane na sztywno
         `class="ikona"` w swoim znaczniku, a druga taka atrybucja z
         `$attributes` ląduje w HTML-u jako DRUGI, zduplikowany atrybut
         `class` — przeglądarka honoruje tylko pierwszy i po cichu pomija
         drugi (zmierzone `DOMDocument::getAttribute()`), więc taka klasa
         nigdy by się nie zastosowała. Kolor nadaje niżej sam
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
