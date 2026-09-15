<x-layout title="Bez odpowiedzi — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Bez odpowiedzi" />
    <h1>{{ $type === 'przepisy' ? 'Przepisy bez odpowiedzi' : 'Ugotowałem bez odpowiedzi' }}</h1>
    @include('pages.admin._bez-odpowiedzi-nawigacja')
    <p>Od najstarszej publikacji. Otwórz treść, żeby przeczytać ją i odpowiedzieć.</p>
    <div class="stack">
        @forelse($items as $item)
            @php
                $author = $type === 'przepisy' ? $item->author : $item->user;
                $published = $type === 'przepisy' ? $item->published_at : $item->created_at;
                $url = $type === 'przepisy' ? route('recipes.show', $item->slug) : route('cooked.show', $item);
            @endphp
            <article class="card">
                <h2>{{ $type === 'przepisy' ? $item->title : $item->recipe->title }}</h2>
                <p><a href="{{ route('profile.show', $author->profile->username) }}">{{ $author->displayName() }}</a>
                    · <time datetime="{{ $published->toIso8601String() }}">{{ $published->format('d.m.Y, H:i') }}</time></p>
                @if($type === 'ugotowane' && $item->note)
                    <p>{{ \Illuminate\Support\Str::limit($item->note, 400) }}</p>
                @endif
                <a class="btn btn-primary" href="{{ $url }}#komentarze">Otwórz i odpowiedz</a>
            </article>
        @empty
            <x-empty-state title="Brak treści oczekujących na odpowiedź">
                <p>W tym widoku nie ma teraz dostępnych Ci {{ $type === 'przepisy' ? 'przepisów' : 'wykonań' }} bez odpowiedzi innej osoby.</p>
            </x-empty-state>
        @endforelse
    </div>
    @if($items->hasMorePages())
        <p class="mt-6"><a class="btn btn-secondary" href="{{ $items->nextPageUrl() }}">Pokaż więcej</a></p>
    @endif
</x-layout>
