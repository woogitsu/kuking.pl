{{--
    Jedna wersja regulaminu albo polityki prywatności z archiwum (#2220,
    kryterium 4). Nad treścią zawsze stoi, z którego dnia jest ta wersja
    i gdzie jest obecna — żeby nikt nie wziął starego brzmienia za
    obowiązujące. `$archiwum` to `App\Domain\Zgody\ArchiwumDokumentu`.
--}}
@php($slownie = \App\Domain\Zgody\ArchiwumDokumentu::dataSlownie($data))
<x-layout
    :title="$archiwum->mianownik.' — wersja z '.$slownie"
    :description="$archiwum->mianownik.' Kuking w wersji z '.$slownie.' — do przeczytania i do pobrania jako plik tekstowy.'"
    :noindexFollow="true"
>
    <div class="notice">
        <p>{{ $archiwum->opisWersji($data) }}</p>
        <p class="historia-akcje">
            <a class="btn btn-secondary" href="{{ route($archiwum->trasa.'.version.download', $data) }}" download>Pobierz tę wersję (plik tekstowy)</a>
            <a class="btn btn-quiet" href="{{ route($archiwum->trasa.'.versions') }}">Wszystkie wersje {{ $archiwum->dopelniaczKrotko }}</a>
            @if($data !== $biezaca)
                <a class="btn btn-quiet" href="{{ route($archiwum->trasa) }}">Obecny tekst {{ $archiwum->dopelniaczKrotko }}</a>
            @endif
        </p>
    </div>

    <article class="prose">
        {!! $html !!}
    </article>
</x-layout>
