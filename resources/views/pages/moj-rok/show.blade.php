{{--
    „Mój rok w kuchni” (#2353, D-333). Prywatne archiwum, `noindex`, tylko dla
    właściciela konta. Spokojne liczby i trzy przepisy — bez porównań, rankingów,
    odznak, procentów i animacji, bez cudzych imion i bez udostępniania.

    Zero NIE jest pokazywane jako wynik: wiersz z zerem po prostu się nie
    pojawia, a pusty rok dostaje jedno łagodne zdanie. Przełącznik roku to
    zwykłe odnośniki (bez skryptu, bez gestów).
--}}
<x-layout :title="'Mój rok w kuchni '.$rok" :noindex="true">
    <p class="mb-3"><a href="{{ route('collections.index') }}">Wróć do zeszytu</a></p>

    <h1>Mój rok w kuchni — {{ $rok }}</h1>
    <p class="mb-5">To podsumowanie widzisz tylko Ty. Nikt inny go nie zobaczy, nie trafia do tablicy ani do wyszukiwarki i nie ma na nic wpływu.</p>

    @if(count($lata) > 1)
        <nav aria-label="Wybór roku" class="mb-5">
            <p class="mb-2"><strong>Inne lata</strong></p>
            <div class="flex flex-wrap gap-3">
                @foreach($lata as $r)
                    @if($r === $rok)
                        <span class="btn btn-primary" aria-current="page">{{ $r }}</span>
                    @else
                        <a class="btn btn-secondary" href="{{ $r === $biezacy ? route('moj-rok.show') : route('moj-rok.rok', $r) }}">{{ $r }}</a>
                    @endif
                @endforeach
            </div>
        </nav>
    @endif

    @if($pusty)
        <x-empty-state title="W tym roku nic tu jeszcze nie ma" :mark="false">
            Gdy opublikujesz danie albo zaznaczysz „Ugotowałem” przy cudzym przepisie, pojawi się to tutaj. Bez pośpiechu.
        </x-empty-state>
    @else
        <section class="card mb-5" aria-labelledby="moj-rok-liczby">
            <h2 class="mt-0" id="moj-rok-liczby">W liczbach</h2>
            @if($dania > 0)
                <p class="m-0 mb-2" data-moj-rok-dania>Opublikowane dania: <strong>{{ $dania }}</strong></p>
            @endif
            @if($wykonania > 0)
                <p class="m-0" data-moj-rok-wykonania>Zaznaczone „Ugotowałem”: <strong>{{ $wykonania }}</strong> {{ \App\Support\Odmiana::rzeczownik($wykonania, 'raz', 'razy', 'razy') }}</p>
            @endif
        </section>

        @if($najczesciej !== [])
            <section class="card mb-5" aria-labelledby="moj-rok-najczesciej">
                <h2 class="mt-0" id="moj-rok-najczesciej">Gotowane najczęściej</h2>
                <div data-moj-rok-najczesciej>
                    @foreach($najczesciej as $wiersz)
                        <p class="m-0 mb-2">
                            <a href="{{ route('recipes.show', $wiersz['przepis']->slug) }}">{{ $wiersz['przepis']->title }}</a>
                            — {{ $wiersz['razy'] }} {{ \App\Support\Odmiana::rzeczownik($wiersz['razy'], 'raz', 'razy', 'razy') }}
                        </p>
                    @endforeach
                </div>
            </section>
        @endif
    @endif

    <p class="meta meta-samodzielne mt-5">Liczymy tylko to, co jest zapisane na Twoim koncie, i tylko dla przepisów, które nadal możesz otworzyć. Podsumowanie możesz wyłączyć w <a href="{{ route('settings.privacy') }}">ustawieniach prywatności</a> — nic się wtedy nie kasuje.</p>
</x-layout>
