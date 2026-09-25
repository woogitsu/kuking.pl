{{--
    Ekran pod podpisanym odnośnikiem „Nie chcę więcej takich listów" z listu
    z życzeniami (issue #1755, D-269), otwartym metodą GET.

    NICZEGO NIE ZAPISUJE. Zgodę wycofuje wyłącznie przycisk niżej (POST
    z tokenem CSRF). Wejście na adres — skaner linków w poczcie, podgląd
    odnośnika, historia przeglądarki — nie jest decyzją człowieka.
    `noindex`, bo adres niesie podpis związany z konkretnym kontem.
--}}
<x-layout title="List z życzeniami" :noindex="true">
    <h1>Nie chcesz dostawać listu z życzeniami?</h1>
    <div class="sekcja-strony">
        <p class="mt-0">
            List z życzeniami urodzinowymi przychodzi raz w roku, rano.
        </p>
        <p class="mb-0">
            Nic jeszcze nie zmieniliśmy. Jeśli nie chcesz go dostawać, kliknij przycisk poniżej.
        </p>
    </div>
    <form method="POST" action="{{ $wypisz }}">
        @csrf
        <button class="btn btn-primary" type="submit">Tak, nie wysyłajcie mi go</button>
    </form>
    <p class="mt-4">
        <a class="btn btn-quiet" href="{{ auth()->check() ? route('home') : route('landing') }}">Wróć do Kuking</a>
    </p>
</x-layout>
