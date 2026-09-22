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
        {{--
            POLE WYBORU W RAMCE `.field`, NIE GOŁY `<select>`.

            ZMIERZONE PRZED POPRAWKĄ (`getComputedStyle` + `getBoundingClientRect`,
            Chromium, okno 320 px): 157 × 23 px i pismo przeglądarki, bo goły
            `<select>` nie trafiał na żadną naszą regułę — te stoją pod
            `.field select` i `.field-input`. To jest 23 px zamiast 48 px celu
            dotknięcia i rozmiar pisma poniżej 18 px, czyli dwa twarde warunki
            z AGENTS.md §5 naraz, na jedynej kontrolce tego ekranu.

            `x-field` tu nie pomoże — ten składnik nie zna `<select>` (obsługuje
            `input` i `textarea`). Dlatego klasy stoją tu wprost; etykieta
            zostaje widoczna i dalej wiąże się przez `for`/`id`.
        --}}
        <div class="field">
            <label for="question-filter">Pokaż pytania</label>
            <select class="field-input" id="question-filter" name="filtr">
                <option value="najnowsze" @selected($filter === 'najnowsze')>Najnowsze</option>
                <option value="bez-odpowiedzi" @selected($filter === 'bez-odpowiedzi')>Bez odpowiedzi</option>
            </select>
        </div>
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
