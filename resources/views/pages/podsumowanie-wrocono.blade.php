{{--
    Ekran po kliknięciu „Jednak chcę je dostawać".

    Drugie zdanie mówi, KIEDY przyjdzie następny list — bo bez tego człowiek
    nie wie, czy ma czekać dzień, czy miesiąc, i wraca sprawdzać ustawienia.

    `noindex`, bo adres niesie podpis związany z konkretnym kontem.
--}}
<x-layout title="Podsumowanie włączone" :noindex="true">
    <h1>Będziemy pisać dalej</h1>

    <div class="sekcja-strony">
        <p class="mt-0">
            Tygodniowe podsumowanie jest znowu włączone. Przyjdzie najwyżej raz
            w tygodniu — i tylko wtedy, gdy będzie o czym pisać.
        </p>

        <p class="mb-0">
            Możesz to zmienić w każdej chwili: w ustawieniach prywatności albo
            odnośnikiem na dole każdego e-maila.
        </p>
    </div>

    <form method="POST" action="{{ $wypisz }}">
        @csrf
        <button class="btn btn-quiet" type="submit">Jednak nie chcę</button>
    </form>

    <p class="mt-4">
        <a class="btn btn-quiet" href="{{ auth()->check() ? route('home') : route('landing') }}">
            Wróć do Kuking
        </a>
    </p>
</x-layout>
