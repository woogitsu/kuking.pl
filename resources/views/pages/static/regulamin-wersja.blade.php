{{--
    Jedna wersja regulaminu z archiwum (#2220, kryterium 4). Nad treścią
    zawsze stoi, z którego dnia jest ta wersja i gdzie jest obecna — żeby
    nikt nie wziął starego brzmienia za obowiązujące.
--}}
@php($slownie = \App\Domain\Zgody\ArchiwumRegulaminu::dataSlownie($data))
<x-layout
    :title="'Regulamin — wersja z '.$slownie"
    :description="'Regulamin Kuking w wersji z '.$slownie.' — do przeczytania i do pobrania jako plik tekstowy.'"
    :noindexFollow="true"
>
    <div class="notice">
        <p>{{ \App\Domain\Zgody\ArchiwumRegulaminu::opisWersji($data) }}</p>
        <p class="historia-akcje">
            <a class="btn btn-secondary" href="{{ route('terms.version.download', $data) }}" download>Pobierz tę wersję (plik tekstowy)</a>
            <a class="btn btn-quiet" href="{{ route('terms.versions') }}">Wszystkie wersje regulaminu</a>
            @if($data !== $biezaca)
                <a class="btn btn-quiet" href="{{ route('terms') }}">Obecny regulamin</a>
            @endif
        </p>
    </div>

    <article class="prose">
        {!! $html !!}
    </article>
</x-layout>
