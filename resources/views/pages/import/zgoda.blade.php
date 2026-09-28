<x-layout title="Odczyt zdjęcia kartki" :noindex="true">
    {{--
        ZGODA „ODCZYT AI” PRZED PIERWSZYM UŻYCIEM (D-296, wzór z D-240).

        Prostym językiem: kto odczyta, co wyślemy, czego nie wyślemy, jak
        wycofać — z komponentu `x-zgoda-odczyt-ai`, tego samego co
        w ustawieniach prywatności (issue #2033). Dwa przyciski tej samej
        wagi wizualnej co do wielkości; „Nie” prowadzi do zwykłego dodawania
        przepisu, bez kary.
    --}}
    <h1>Zdjęcie odczyta komputer firmy OpenAI</h1>

    <div class="panel-formularza stack">
        {{-- Treść i formularz: jedno źródło dla obu wejść (issue #2033). --}}
        <x-zgoda-odczyt-ai skad="import" :odmowa="route('recipes.create')" />
    </div>
</x-layout>
