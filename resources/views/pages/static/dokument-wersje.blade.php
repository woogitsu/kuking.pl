{{--
    Wszystkie wersje regulaminu albo polityki prywatności (#2220, kryterium 4).
    Zwykłe odnośniki, bez JavaScriptu: „Przeczytaj” otwiera wersję w serwisie,
    „Pobierz” zapisuje ją jako plik tekstowy. Poza indeksem — patrz
    `ArchiwumDokumentuController`. `$archiwum` to `App\Domain\Zgody\ArchiwumDokumentu`.
--}}
@php($dataSlownie = fn (string $data): string => \App\Domain\Zgody\ArchiwumDokumentu::dataSlownie($data))
<x-layout
    :title="'Wszystkie wersje '.$archiwum->dopelniacz"
    :description="'Wszystkie opublikowane wersje '.$archiwum->dopelniacz.' Kuking z datami — do przeczytania i do pobrania jako plik tekstowy.'"
    :noindexFollow="true"
>
    <p><a class="btn btn-quiet" href="{{ route($archiwum->trasa) }}">Wróć do {{ $archiwum->dopelniaczKrotko }}</a></p>

    <h1>Wszystkie wersje {{ $archiwum->dopelniacz }}</h1>
    <p class="text-lead">
        Tu jest każda opublikowana wersja {{ $archiwum->dopelniacz }} Kuking, z datą. Możesz ją przeczytać
        albo pobrać jako plik tekstowy i zachować u siebie.
    </p>
    <p class="meta meta-samodzielne">
        Data wersji to ta sama data, którą widać na górze {{ $archiwum->dopelniaczKrotko }} („opisuje stan serwisu na …”).
        @if($archiwum->slug === \App\Domain\Zgody\ArchiwumDokumentu::REGULAMIN)
            Przy zakładaniu konta zapisujemy datę wersji regulaminu, którą akceptujesz — to właśnie ta data.
        @else
            Przy zakładaniu konta i przy każdej zmianie zgody zapisujemy datę wersji polityki, która wtedy obowiązuje — to właśnie ta data.
        @endif
    </p>

    @if($archiwum->starszeNaProsbe && $archiwum->najstarsza() !== null)
        @include('pages.static.partials.dokument-starsze-na-prosbe', ['archiwum' => $archiwum])
    @endif

    <ol class="list-none p-0" aria-label="Wersje {{ $archiwum->dopelniacz }}">
        @foreach($wersje as $wersja)
            <li class="historia-wersja card">
                <h2 class="historia-wersja-naglowek">
                    Wersja z {{ $dataSlownie($wersja) }}
                    @if($wersja === $biezaca)
                        <span class="badge badge-spokojny">Obecna</span>
                    @endif
                </h2>
                <p class="historia-akcje">
                    <a class="btn btn-secondary" href="{{ route($archiwum->trasa.'.version', $wersja) }}">Przeczytaj wersję z {{ $dataSlownie($wersja) }}</a>
                    <a class="btn btn-secondary" href="{{ route($archiwum->trasa.'.version.download', $wersja) }}" download>Pobierz wersję z {{ $dataSlownie($wersja) }} (plik tekstowy)</a>
                </p>
            </li>
        @endforeach
    </ol>
</x-layout>
