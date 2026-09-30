{{--
    Wszystkie wersje regulaminu (#2220, kryterium 4). Zwykłe odnośniki, bez
    JavaScriptu: „Przeczytaj” otwiera wersję w serwisie, „Pobierz” zapisuje
    ją jako plik tekstowy. Poza indeksem — patrz `ArchiwumRegulaminuController`.
--}}
<x-layout
    title="Wszystkie wersje regulaminu"
    description="Wszystkie opublikowane wersje regulaminu Kuking z datami — do przeczytania i do pobrania jako plik tekstowy."
    :noindexFollow="true"
>
    <p><a class="btn btn-quiet" href="{{ route('terms') }}">Wróć do regulaminu</a></p>

    <h1>Wszystkie wersje regulaminu</h1>
    <p class="text-lead">
        Tu jest każda opublikowana wersja regulaminu Kuking, z datą. Możesz ją przeczytać
        albo pobrać jako plik tekstowy i zachować u siebie.
    </p>
    <p class="meta meta-samodzielne">
        Data wersji to ta sama data, którą widać na górze regulaminu („opisuje stan serwisu na …”).
        Przy zakładaniu konta zapisujemy datę wersji regulaminu, którą akceptujesz — to właśnie ta data.
    </p>

    <ol class="list-none p-0" aria-label="Wersje regulaminu">
        @foreach($wersje as $wersja)
            <li class="historia-wersja card">
                <h2 class="historia-wersja-naglowek">
                    Wersja z {{ \App\Domain\Zgody\ArchiwumRegulaminu::dataSlownie($wersja) }}
                    @if($wersja === $biezaca)
                        <span class="badge badge-spokojny">Obecna</span>
                    @endif
                </h2>
                <p class="historia-akcje">
                    <a class="btn btn-secondary" href="{{ route('terms.version', $wersja) }}">Przeczytaj wersję z {{ \App\Domain\Zgody\ArchiwumRegulaminu::dataSlownie($wersja) }}</a>
                    <a class="btn btn-secondary" href="{{ route('terms.version.download', $wersja) }}" download>Pobierz wersję z {{ \App\Domain\Zgody\ArchiwumRegulaminu::dataSlownie($wersja) }} (plik tekstowy)</a>
                </p>
            </li>
        @endforeach
    </ol>
</x-layout>
