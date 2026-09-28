{{--
    Ekran po wypisaniu z listu z życzeniami (issue #1755, D-269). Rzecz jest
    już zrobiona — pierwsze zdanie mówi to w czasie przeszłym. Przycisk
    powrotny na tej samej stronie: naprawa pomyłki jednym kliknięciem, bez
    logowania. `noindex`, bo adres niesie podpis związany z kontem.
--}}
<x-layout title="Wypisano z listu z życzeniami" :noindex="true">
    <h1>Nie wyślemy już listu z życzeniami</h1>
    <div class="sekcja-strony">
        <p class="mt-0">
            Zgoda na e-mail z życzeniami urodzinowymi jest wycofana. O nic nie zapytamy.
        </p>
        <p class="mb-0">
            Twoje konto, wpisy i przepisy zostają bez zmian — wyłączyliśmy tylko ten jeden list.
        </p>
    </div>
    <form method="POST" action="{{ $powrot }}">
        @csrf
        <button class="btn btn-secondary" type="submit">Jednak chcę go dostawać</button>
    </form>
</x-layout>
