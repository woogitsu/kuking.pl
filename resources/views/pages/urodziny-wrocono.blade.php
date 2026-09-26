{{--
    Ekran po „Jednak chcę go dostawać" (issue #1755, D-269). Zgoda wróciła
    z nowym wpisem w dzienniku zgód (źródło: link powrotny).
    `noindex`, bo adres niesie podpis związany z konkretnym kontem.
--}}
<x-layout title="List z życzeniami włączony" :noindex="true">
    <h1>List z życzeniami przyjdzie</h1>
    <div class="sekcja-strony">
        <p class="mt-0">
            Zgoda na e-mail z życzeniami urodzinowymi jest znowu włączona. List przyjdzie rano w dniu urodzin.
        </p>
        <p class="mb-0">
            Możesz to zmienić w każdej chwili: w ustawieniach urodzin albo odnośnikiem na dole listu.
        </p>
    </div>
    <form method="POST" action="{{ $wypisz }}">
        @csrf
        <button class="btn btn-secondary" type="submit">Jednak nie chcę</button>
    </form>
</x-layout>
