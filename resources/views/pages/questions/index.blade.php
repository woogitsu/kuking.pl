<x-layout title="Poradźcie" description="Zapytaj innych o codzienne gotowanie. Ktoś to już robił i chętnie powie, jak.">
    <h1>Poradźcie</h1>
    <p class="text-lead">Ktoś to już robił i chętnie powie, jak.</p>
    <p>Pytanie do innych jest w porządku.</p>
    <p><a class="btn btn-primary" href="{{ route('questions.create') }}">Zadaj pytanie</a></p>

    <section class="card mb-6" aria-labelledby="pomoz-odpowiedziec">
        <h2 id="pomoz-odpowiedziec">Pomóż odpowiedzieć</h2>
        <p>Zajrzyj do pytań bez odpowiedzi. Może przyda się Twoje doświadczenie.</p>
        @if($tag)
            <p>W tagu: {{ $tag->name }}.</p>
        @endif
        <a id="pytania-bez-odpowiedzi" class="btn btn-secondary"
           href="{{ route('questions.index', ['filtr' => 'bez-odpowiedzi', 'tag' => $tag?->slug]) }}"
           @if($filter === 'bez-odpowiedzi') aria-current="page" @endif>Czeka na odpowiedź ({{ $unansweredCount }})</a>
    </section>

    <form method="GET" action="{{ route('questions.index') }}" class="stack mb-6">
        <label for="question-filter">Pokaż pytania</label>
        <select id="question-filter" name="filtr">
            <option value="najnowsze" @selected($filter === 'najnowsze')>Najnowsze</option>
            <option value="bez-odpowiedzi" @selected($filter === 'bez-odpowiedzi')>Bez odpowiedzi</option>
        </select>
        @if($tag)
            <input type="hidden" name="tag" value="{{ $tag->slug }}">
            <p>Tag: {{ $tag->name }}. <a href="{{ route('questions.index', ['filtr' => $filter]) }}">Pokaż wszystkie tagi</a></p>
        @endif
        <button type="submit" class="btn btn-secondary">Pokaż</button>
    </form>

    <div class="stack">
        @forelse($questions as $question)
            <article class="card">
                <h2><a href="{{ route('questions.show', $question) }}">{{ $question->title }}</a></h2>
                <p class="meta">{{ $question->answer_count }} {{ \App\Support\Odmiana::rzeczownik($question->answer_count, 'odpowiedź', 'odpowiedzi', 'odpowiedzi') }}</p>
                @if($question->answer_count === 0)
                    <a class="btn btn-secondary" href="{{ $question->url() }}#komentarze">Otwórz i odpowiedz</a>
                @endif
            </article>
        @empty
            <x-empty-state title="Nie ma pytań pasujących do tego wyboru">
                Możesz zmienić filtr i zajrzeć do pozostałych pytań.
            </x-empty-state>
        @endforelse
    </div>
    <x-show-more :paginator="$questions" czego="pytań" />
</x-layout>
