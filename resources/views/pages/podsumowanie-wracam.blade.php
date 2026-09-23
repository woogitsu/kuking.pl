{{--
    Ekran pod podpisanym odnośnikiem „Jednak chcę je dostawać" otwartym
    metodą GET (#1403).

    NICZEGO NIE ZAPISUJE. Zgodę na list włącza wyłącznie przycisk niżej
    (POST z tokenem CSRF). Wejście na adres — z historii przeglądarki,
    z podglądu linku, przez przeglądarkę pobierającą strony „na zapas" —
    nie jest zgodą człowieka.

    `noindex`, bo adres niesie podpis związany z konkretnym kontem.
--}}
<x-layout title="Tygodniowe podsumowanie" :noindex="true">
    <h1>Chcesz znowu dostawać podsumowanie?</h1>

    <div class="sekcja-strony">
        <p class="mt-0">
            Tygodniowe podsumowanie przychodzi najwyżej raz w tygodniu — i tylko
            wtedy, gdy jest o czym pisać.
        </p>

        <p class="mb-0">
            Nic jeszcze nie zmieniliśmy. Jeśli chcesz je dostawać, kliknij przycisk poniżej.
        </p>
    </div>

    <form method="POST" action="{{ $powrot }}">
        @csrf
        <button class="btn btn-primary" type="submit">Tak, chcę je dostawać</button>
    </form>

    <p class="mt-4">
        <a class="btn btn-quiet" href="{{ auth()->check() ? route('home') : route('landing') }}">
            Wróć do Kuking
        </a>
    </p>
</x-layout>
