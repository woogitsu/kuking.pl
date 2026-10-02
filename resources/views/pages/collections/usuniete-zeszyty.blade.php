{{--
    „Usunięte zeszyty” (#2567, D-333). Własny, omyłkowo usunięty PRYWATNY zeszyt
    można odzyskać do końca retencji (`kuking.usuniete_tresci.retention_days`).
    Wraca jako prywatny, z zapisami, własnymi dopiskami i datami zapisania —
    bez powiadomień i bez publikacji.

    Lista pokazuje tylko to, co da się teraz odzyskać. Zeszytów objętych sprawą
    moderacyjną nie nazywamy; ekran ma jedno zdanie o tym, dlaczego czegoś może
    brakować. Zwykły POST, bez JavaScriptu; przycisk ma pełny napis i 48 px (`.btn`).
--}}
<x-layout title="Usunięte zeszyty" :noindex="true">
    <div class="marka-zeszyt stack kolumna-czytania">
        <p class="mb-0"><a href="{{ route('collections.index') }}">Wróć do zeszytu</a></p>

        <h1>Usunięte zeszyty</h1>

        <p>
            Prywatny zeszyt usunięty przez pomyłkę można odzyskać przez {{ $dni }} {{ \App\Support\Odmiana::rzeczownik($dni, 'dzień', 'dni', 'dni') }} od usunięcia.
            Wróci jako prywatny, razem z zapisami, Twoimi dopiskami i datami zapisania.
            Nikt nie dostanie powiadomienia i nic się nie opublikuje. Po tym terminie zeszyt jest usuwany na stałe i nie da się go odzyskać.
        </p>

        @if($zeszyty->isEmpty())
            <x-empty-state title="Nie masz teraz usuniętych zeszytów do odzyskania" :mark="false" />
        @else
            <ul class="stack list-none p-0 m-0" id="lista-usunietych-zeszytow">
                @foreach($zeszyty as $zeszyt)
                    <li class="card" data-usuniety-zeszyt="{{ $zeszyt->collection_id }}">
                        <h2 class="mt-0 mb-2 text-xl" id="usuniety-zeszyt-{{ $zeszyt->collection_id }}">{{ $zeszyt->name }}</h2>
                        <p class="meta m-0">
                            {{ $zeszyt->items_count }} {{ \App\Support\Odmiana::rzeczownik($zeszyt->items_count, 'zapis', 'zapisy', 'zapisów') }}.
                            Usunięty {{ \App\Support\Czas::data($zeszyt->deleted_at, 'j F Y, H:i') }}.
                        </p>
                        <p class="m-0 mt-2" data-termin>Możesz go odzyskać do {{ \App\Support\Czas::data(\App\Domain\Collections\Odzyskiwanie\OdzyskajUsunietyZeszyt::termin($zeszyt), 'j F Y, H:i') }}.</p>
                        <form class="mt-3" method="POST" action="{{ route('collections.deleted.recover', $zeszyt->collection_id) }}" novalidate>
                            @csrf
                            <button class="btn btn-primary" type="submit" aria-label="Odzyskaj zeszyt: {{ $zeszyt->name }}" aria-describedby="usuniety-zeszyt-{{ $zeszyt->collection_id }}">Odzyskaj zeszyt</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="notice">
            Nie widzisz tu zeszytu, który usunięto? Tą drogą nie wracają zeszyty usunięte dawniej niż {{ $dni }}
            {{ \App\Support\Odmiana::rzeczownik($dni, 'dzień', 'dni', 'dni') }} temu, publiczne i wspólne, usunięte razem z kontem
            oraz te, którymi zajmuje się moderacja. Skrót w „Moje” nie wraca sam — ustawisz go ponownie w odzyskanym zeszycie.
            <a href="{{ route('kontakt') }}">Napisz do nas</a>, jeśli chcesz o coś zapytać.
        </p>
    </div>
</x-layout>
