{{--
    Ekran po „Jednak chcę go dostawać" (#1903, D-333). Zgoda wróciła z nowym
    wpisem w dzienniku zgód (źródło: link powrotny). `noindex`, bo adres
    niesie podpis związany z konkretnym kontem.
--}}
<x-layout title="Sobotnie przypomnienie włączone" :noindex="true">
    <h1>Sobotnie przypomnienie przyjdzie</h1>
    <div class="sekcja-strony">
        <p class="mt-0">
            Zgoda na sobotni e-mail o produktach do zużycia jest znowu włączona. List przyjdzie w sobotę rano, jeśli będzie co na nim wymienić.
        </p>
        <p class="mb-0">
            Możesz to zmienić w każdej chwili: na stronie „Co mam w domu” albo odnośnikiem na dole listu.
        </p>
    </div>
    <form method="POST" action="{{ $wypisz }}">
        @csrf
        <button class="btn btn-secondary" type="submit">Jednak nie chcę</button>
    </form>
</x-layout>
