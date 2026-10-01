{{--
    Ekran po wypisaniu z sobotniego przypomnienia o produktach (#1903, D-333).
    Rzecz jest już zrobiona — pierwsze zdanie mówi to w czasie przeszłym.
    Przycisk powrotny na tej samej stronie: naprawa pomyłki jednym
    kliknięciem, bez logowania. `noindex`, bo adres niesie podpis konta.
--}}
<x-layout title="Wypisano z sobotniego przypomnienia" :noindex="true">
    <h1>Nie wyślemy już sobotniego przypomnienia</h1>
    <div class="sekcja-strony">
        <p class="mt-0">
            Zgoda na sobotni e-mail o produktach do zużycia jest wycofana. O nic nie zapytamy.
        </p>
        <p class="mb-0">
            Twoje konto i lista „Co mam w domu” zostają bez zmian — wyłączyliśmy tylko ten jeden list.
        </p>
    </div>
    <form method="POST" action="{{ $powrot }}">
        @csrf
        <button class="btn btn-secondary" type="submit">Jednak chcę go dostawać</button>
    </form>
</x-layout>
