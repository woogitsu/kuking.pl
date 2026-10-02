{{--
    „Usunięte przepisy” (#2620, D-333). Własny, omyłkowo usunięty przepis
    można odzyskać do końca retencji (`kuking.usuniete_tresci.retention_days`).
    Wraca jako PRYWATNY szkic — publikacja jest osobną, świadomą decyzją.

    Lista pokazuje tylko to, co da się teraz odzyskać. Przepisów objętych
    sprawą moderacyjną nie nazywamy; ekran ma jedno zdanie o tym, dlaczego
    czegoś może brakować. Zwykły POST, bez JavaScriptu; przycisk ma pełny
    napis i 48 px (`.btn`).
--}}
<x-layout title="Usunięte przepisy" :noindex="true">
    <div class="marka-zeszyt stack kolumna-czytania">
        <p class="mb-0"><a href="{{ route('collections.index') }}">Wróć do zeszytu</a></p>

        <h1>Usunięte przepisy</h1>

        <p>
            Przepis usunięty przez pomyłkę można odzyskać przez {{ $dni }} {{ \App\Support\Odmiana::rzeczownik($dni, 'dzień', 'dni', 'dni') }} od usunięcia.
            Wróci jako szkic widoczny tylko dla Ciebie — nie trafi do żadnego feedu ani cudzego zeszytu,
            dopóki go nie opublikujesz. Po tym terminie przepis jest usuwany na stałe i nie da się go odzyskać.
        </p>

        @if($przepisy->isEmpty())
            <x-empty-state title="Nie masz teraz usuniętych przepisów do odzyskania" :mark="false" />
        @else
            <ul class="stack list-none p-0 m-0" id="lista-usunietych-przepisow">
                @foreach($przepisy as $przepis)
                    <li class="card" data-usuniety-przepis="{{ $przepis->getKey() }}">
                        <h2 class="mt-0 mb-2 text-xl" id="usuniety-{{ $przepis->getKey() }}">{{ $przepis->title }}</h2>
                        <p class="meta m-0">Usunięty {{ \App\Support\Czas::data($przepis->deleted_at, 'j F Y, H:i') }}.</p>
                        <p class="m-0 mt-2" data-termin>Możesz go odzyskać do {{ \App\Support\Czas::data(\App\Domain\Recipes\OdzyskajUsunietyPrzepis::termin($przepis), 'j F Y, H:i') }}.</p>
                        <form class="mt-3" method="POST" action="{{ route('collections.deleted-recipes.recover', $przepis->getKey()) }}" novalidate>
                            @csrf
                            <button class="btn btn-primary" type="submit" aria-label="Odzyskaj przepis: {{ $przepis->title }}" aria-describedby="usuniety-{{ $przepis->getKey() }}">Odzyskaj przepis</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="notice">
            Nie widzisz tu przepisu, który usunięto? Tą drogą nie wracają przepisy usunięte dawniej niż {{ $dni }}
            {{ \App\Support\Odmiana::rzeczownik($dni, 'dzień', 'dni', 'dni') }} temu, usunięte razem z kontem
            oraz te, którymi zajmuje się moderacja. <a href="{{ route('kontakt') }}">Napisz do nas</a>, jeśli chcesz o coś zapytać.
        </p>
    </div>
</x-layout>
