{{--
    404 — nie ma takiej strony (issue #81).

    Kod błędu zostaje w tytule karty przeglądarki i w statusie HTTP, ale
    NIE jest treścią ekranu. Człowiek, który trafił tu z linku od córki albo
    z zakładki sprzed roku, nie potrzebuje liczby — potrzebuje wiedzieć,
    że to nie on coś zepsuł, i mieć trzy miejsca, do których może pójść.
--}}
<x-layout title="Nie znaleźliśmy tej strony" :noindex="true">
    <h1>Nie znaleźliśmy tej strony</h1>

    <p class="mb-5">
        Adres jest niepełny albo strona została usunięta przez osobę, która ją
        dodała. To nie jest Twoja wina i nic się nie zepsuło.
    </p>

    <p class="mb-5">
        Jeśli adres przepisywałeś z kartki — sprawdź, czy nie zgubiła się kropka
        albo ukośnik. Jeśli przyszedł mailem, otwórz go jeszcze raz z wiadomości,
        zamiast przepisywać.
    </p>

    <div class="form-actions">
        <a class="btn btn-primary" href="{{ auth()->check() ? route('home') : route('landing') }}">Strona główna</a>
        <a class="btn btn-secondary" href="{{ route('search') }}">Poszukaj przepisu</a>
        <a class="btn btn-quiet" href="{{ route('help') }}">Pomoc</a>
    </div>
</x-layout>
