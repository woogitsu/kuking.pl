@php
    // Niedokończone szkice przepisów. Pokazujemy je od razu na wejściu,
    // bo najczęstsze pytanie po przerwanym kreatorze brzmi „gdzie to jest?”.
    $niedokonczoneSzkice = auth()->user()
        ?->recipes()
        ->where('status', \App\Models\Recipe::STATUS_DRAFT)
        ->orderByDesc('updated_at')
        ->limit(3)
        ->get() ?? collect();
@endphp

<x-layout title="Dodaj" :noindex="true">
    {{--
        PRAWA SZYNA (issue #205).

        CO CZŁOWIEK, KTÓRY TU STOI, MA W GŁOWIE: „czy to zdjęcie się nadaje".
        Nie „jak zrobić zdjęcie kulinarne" — to jest cztery rzeczy, które
        naprawdę zmieniają wynik, a piąta linijka zdejmuje presję, bo bez niej
        cała reszta czyta się jak wymagania wstępne.

        TO NIE JEST POUCZANIE I DLATEGO NIE STOI W GŁÓWNEJ KOLUMNIE.
        W środku są dwie decyzje do podjęcia („zdjęcie" albo „cały przepis")
        i nic nie może stanąć między nimi a kliknięciem. Podpowiedź obok
        wolno przeczytać albo pominąć.

        ZERO ZAPYTAŃ DO BAZY: to jest tekst, nie lista czegokolwiek.
    --}}
    <x-slot:rail>
        <x-szyna-blok tytul="Zdjęcie, które dobrze wychodzi" id="szyna-zdjecie" ikona="image">
            <ul class="stack-tight">
                <li>Światło od okna wychodzi lepiej niż lampa pod sufitem.</li>
                <li>Bliżej niż dalej — jedno danie, nie cały blat.</li>
                <li>Z góry albo pod skosem, tak jak się na to patrzy przy stole.</li>
                <li>Telefon w poziomie, jeśli talerz jest szeroki.</li>
            </ul>
            <p class="mb-0">Nie musi być ładnie. Ma być Twoje.</p>
        </x-szyna-blok>
    </x-slot:rail>

    <h1>Co chcesz dodać?</h1>
    <p class="mb-6">Nie musisz od razu pisać całego przepisu — samo zdjęcie wystarczy.</p>

    @if($niedokonczoneSzkice->isNotEmpty())
        <div class="notice">
            <p class="mt-0">
                <strong>{{ $niedokonczoneSzkice->count() === 1 ? 'Masz niedokończony przepis.' : 'Masz niedokończone przepisy.' }}</strong>
                Nic z nich nie zginęło — możesz wrócić do pisania.
            </p>
            <ul class="stack-tight list-none p-0 m-0">
                @foreach($niedokonczoneSzkice as $szkic)
                    <li>
                        <a class="btn btn-secondary" href="{{ route('recipes.create', ['szkic' => $szkic->getKey()]) }}">
                            Dokończ: {{ $szkic->title }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="stack">
        <a class="kafel-akcji" href="{{ route('posts.create') }}">
            <h2 class="mt-0">Zdjęcie i kilka słów</h2>
            {{-- BEZ „Zajmuje niecałą minutę": obietnica z miarą, której nie
                 mierzymy. Zdanie przed nią i tak mówi to samo lepiej — wymienia
                 kroki zamiast obiecywać czas, który zależy od tego, jak szybko
                 pójdzie zdjęcie z telefonu. --}}
            <p class="mb-0">Najprostsza rzecz. Wybierasz zdjęcie, piszesz jedno zdanie i gotowe.</p>
        </a>

        <a class="kafel-akcji" href="{{ route('recipes.create') }}">
            <h2 class="mt-0">Cały przepis</h2>
            <p class="mb-0">Składniki i przygotowanie, żeby ktoś inny mógł to u siebie zrobić. Możesz zapisać szkic i wrócić później.</p>
        </a>
        @if(config('kuking.questions.enabled'))
            <a class="kafel-akcji" href="{{ route('questions.create') }}">
                <h2 class="mt-0">Zadaj pytanie</h2>
                <p class="mb-0">Poradźcie — ktoś to już robił i chętnie powie, jak.</p>
            </a>
        @endif
    </div>
</x-layout>
