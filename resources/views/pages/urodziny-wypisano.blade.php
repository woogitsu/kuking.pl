{{--
    Ekran po kliknięciu „Nie chcę więcej takich listów" w liście z życzeniami
    (issue #1755, etap c). Rzecz jest już zrobiona, zanim ta strona się
    wyświetli — pierwsze zdanie mówi to w czasie przeszłym. `noindex`, bo
    adres niesie podpis związany z konkretnym kontem.
--}}
<x-layout title="Wypisano z listu z życzeniami" :noindex="true">
    <h1>Nie wyślemy już listu z życzeniami</h1>
    <div class="sekcja-strony">
        <p class="mt-0">
            Zgoda na e-mail z życzeniami urodzinowymi jest wycofana. O nic nie zapytamy.
        </p>
        <p class="mb-0">
            Twoje konto, wpisy i przepisy zostają bez zmian. Jeśli to pomyłka,
            zaznacz zgodę ponownie w <a href="{{ route('settings.birthday') }}">ustawieniach urodzin</a>
            (trzeba się zalogować).
        </p>
    </div>
</x-layout>
