{{--
    Wygasły link „Jednak chcę go dostawać" (#1903, D-333). Link włącza zgodę
    bez logowania, więc żyje godzinę; po czasie odsyłamy do Ustawień.
    `noindex`: adres niesie podpis konta.
--}}
<x-layout title="Link wygasł" :noindex="true">
    <h1>Ten link wygasł</h1>
    <div class="sekcja-strony">
        <p class="mt-0">
            Link „Jednak chcę go dostawać” działa przez godzinę od wypisania, żeby nikt niepowołany nie włączył Ci e-maila.
        </p>
        <p class="mb-0">
            Zaloguj się i włącz sobotnie przypomnienie w Ustawieniach albo na stronie „Co mam w domu”. Nic się nie zmieniło: przypomnienie zostaje wyłączone.
        </p>
    </div>
    <p>
        <a class="btn btn-primary" href="{{ route('login') }}">Zaloguj się</a>
    </p>
</x-layout>
