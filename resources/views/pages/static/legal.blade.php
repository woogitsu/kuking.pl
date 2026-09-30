<x-layout :title="$pageTitle" :description="$pageDescription">
    @isset($archiwum)
        {{-- Pobranie i archiwum wersji regulaminu albo polityki (#2220, kryterium 4). Zwykłe odnośniki, bez JS. --}}
        <p class="historia-akcje">
            <a class="btn btn-secondary" href="{{ route($archiwum->trasa.'.version.download', $archiwum->biezaca()) }}" download>Pobierz {{ $archiwum->biernikKrotko }} (plik tekstowy)</a>
            <a class="btn btn-quiet" href="{{ route($archiwum->trasa.'.versions') }}">Wszystkie wersje {{ $archiwum->dopelniaczKrotko }}</a>
        </p>
    @endisset
    <article class="prose">
        {!! $html !!}
    </article>
</x-layout>
