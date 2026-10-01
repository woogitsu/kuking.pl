{{--
    Ekran pod podpisanym odnośnikiem „Nie chcę więcej takich listów" z sobotniego
    przypomnienia o produktach do zużycia (#1903, D-333), otwartym metodą GET.

    NICZEGO NIE ZAPISUJE. Zgodę wycofuje wyłącznie przycisk niżej (POST
    z tokenem CSRF). Wejście na adres — skaner linków w poczcie, podgląd
    odnośnika, historia przeglądarki — nie jest decyzją człowieka.
    `noindex`, bo adres niesie podpis związany z konkretnym kontem.
--}}
<x-layout title="Sobotnie przypomnienie" :noindex="true">
    <h1>Nie chcesz dostawać sobotniego przypomnienia?</h1>
    <div class="sekcja-strony">
        <p class="mt-0">
            Sobotni list przychodzi raz w tygodniu, rano, i tylko wtedy, gdy na Twojej liście „Co mam w domu” jest produkt z terminem do zużycia.
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
