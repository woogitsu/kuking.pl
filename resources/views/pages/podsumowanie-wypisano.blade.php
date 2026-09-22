{{--
    Ekran po kliknięciu „Nie chcę tych listów" w stopce podsumowania.

    RZECZ JEST JUŻ ZROBIONA, ZANIM TA STRONA SIĘ WYŚWIETLI — i pierwsze zdanie
    ma to powiedzieć w czasie przeszłym dokonanym. Ekran, który mówi „czy na
    pewno?", jest ekranem, na którym część ludzi zamiast tego oznaczy naszą
    pocztę jako spam (docs/decyzje/POCZTA.md §3).

    `noindex`, bo adres niesie podpis związany z konkretnym kontem.

    Bez gry słowem „kuKING" — D-009: nie w komunikacie, przy którym ktoś
    właśnie z czegoś rezygnuje.
--}}
<x-layout title="Wypisano z podsumowania" :noindex="true">
    <h1>Nie będziemy już pisać</h1>

    <div class="sekcja-strony">
        <p class="mt-0">
            Tygodniowe podsumowanie jest wyłączone. O nic nie zapytamy.
        </p>

        <p class="mb-0">
            Twoje konto, wpisy i przepisy zostają bez zmian — wyłączyliśmy
            tylko ten jeden list. Poczta potrzebna do działania konta
            (nowe hasło, potwierdzenie adresu) przychodzi dalej.
        </p>
    </div>

    {{--
        Przycisk powrotny NA TEJ SAMEJ STRONIE. Powód techniczny: skanery
        odnośników w firmowej poczcie otwierają linki z listów same z siebie
        i potrafią kogoś wypisać bez jego wiedzy. Naprawa musi być tak samo
        krótka jak pomyłka — jedno kliknięcie, bez logowania.
    --}}
    <form method="POST" action="{{ $powrot }}">
        @csrf
        <button class="btn btn-secondary" type="submit">Jednak chcę je dostawać</button>
    </form>

    <p class="mt-4">
        <a class="btn btn-quiet" href="{{ auth()->check() ? route('home') : route('landing') }}">
            Wróć do Kuking
        </a>
    </p>
</x-layout>
