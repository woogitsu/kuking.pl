<x-layout :title="$pageTitle" :description="$pageDescription">
    @isset($wersjaRegulaminu)
        {{-- Pobranie i archiwum wersji regulaminu (#2220, kryterium 4). Zwykłe odnośniki, bez JS. --}}
        <p class="historia-akcje">
            <a class="btn btn-secondary" href="{{ route('terms.version.download', $wersjaRegulaminu) }}" download>Pobierz regulamin (plik tekstowy)</a>
            <a class="btn btn-quiet" href="{{ route('terms.versions') }}">Wszystkie wersje regulaminu</a>
        </p>
    @endisset
    <article class="prose">
        {!! $html !!}
    </article>
</x-layout>
