<x-layout title="Pytania bez odpowiedzi — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Pytania bez odpowiedzi" />
    <h1>Pytania bez odpowiedzi</h1>
    @include('pages.admin._bez-odpowiedzi-nawigacja')
    <p>Czeka na odpowiedź ({{ $items->total() }}). Od najstarszego pytania.</p>
    <div class="stack" id="lista-pytan">
        @forelse($items as $item)
            <article class="card" data-klucz="pytanie-{{ $item->getKey() }}">
                <h2><a href="{{ $item->url() }}">{{ $item->title }}</a></h2>
                <p>{{ $item->author->displayName() }} · <time datetime="{{ $item->published_at->toIso8601String() }}">{{ \App\Support\Czas::data($item->published_at, 'j F Y, H:i') }}</time></p>
                <a class="btn btn-primary" href="{{ $item->url() }}#komentarze">Otwórz i odpowiedz</a>
            </article>
        @empty
            <x-empty-state title="Żadne pytanie nie czeka">Nie ma teraz dostępnych Ci pytań bez odpowiedzi innej osoby.</x-empty-state>
        @endforelse
    </div>
    <x-show-more :paginator="$items" czego="pytań" lista="lista-pytan" />
</x-layout>
