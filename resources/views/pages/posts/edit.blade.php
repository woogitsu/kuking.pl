{{--
    Edycja wpisu: tekst, widoczność, tagi.

    ZDJĘCIA CELOWO NIE SĄ TU — mają już swój ekran, „Zdjęcia w tym wpisie"
    (`posts.media.edit`, `PostMediaController`). Powielenie tego pola tutaj
    dałoby dwie drogi do tego samego stanu (AGENTS.md §4).

    Wzorzec pól i błędów zerżnięty z `pages/posts/create.blade.php`: te same
    trzy pola, te same komunikaty, żeby autor nie uczył się dwóch formularzy
    do tej samej rzeczy.
--}}
@php($question = $post->kind === \App\Models\Post::KIND_QUESTION)
<x-layout :title="$question ? 'Edytuj pytanie' : 'Edytuj wpis'" :noindex="true">
    <h1>{{ $question ? 'Edytuj pytanie' : 'Edytuj wpis' }}</h1>

    <x-error-summary />

    {{-- Issue #981: zapis odrzucony, bo wpis zmienił się w innej karcie.
         Obie wersje na jednym ekranie: zapisana tutaj, Twoja w formularzu. --}}
    @if(session('konflikt_edycji') === true)
        <section class="panel-formularza mb-6" aria-labelledby="wersja-zapisana">
            <h2 id="wersja-zapisana">Tak ten wpis jest zapisany teraz</h2>
            @if($question)
                <p><strong>Pytanie:</strong> {{ $post->title }}</p>
            @endif
            <p class="whitespace-pre-line">{{ $post->body ?? '(bez tekstu)' }}</p>
            <p><strong>Kto widzi:</strong> {{ ['public' => 'Wszyscy', 'followers' => 'Tylko osoby, które mnie obserwują', 'private' => 'Tylko ja'][$post->visibility] ?? $post->visibility }}</p>
            @if($post->tags->isNotEmpty())
                <p><strong>Tagi:</strong> {{ $post->tags->pluck('name')->implode(', ') }}</p>
            @endif
            <p>Twoja wersja jest niżej, w formularzu — nic z niej nie zginęło.</p>
        </section>
    @endif

    <form class="panel-formularza" method="POST" action="{{ route('posts.update', $post) }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="_tag_form_post_id" value="{{ $post->getKey() }}">
        <input type="hidden" name="wersja_edycji" value="{{ $wersjaEdycji }}">
        @if($question)
            <x-field name="title" label="O co chcesz zapytać?" :value="$post->title" help="Od 10 do 180 znaków." required />
        @endif

        <div data-tagi-opis data-tagi-endpoint="{{ route('tags.suggestions') }}"
             data-tagi-min="{{ \App\Support\LimityTagow::minZnakow() }}"
             data-tagi-max="{{ config('kuking.tags.suggestions_query_max_length') }}">
        <x-field
            name="body"
            :label="$question ? 'Napisz trochę więcej' : 'Napisz kilka słów'"
            type="textarea"
            :rows="5"
            :value="$post->body"
            help="Na przykład: „Rosół na niedzielę, z kaczki od sąsiada. Wyszedł złoty.”"
        />
            <x-tagi-formularz :tag-names="$tagNames" :sugestie-tagow="$sugestieTagow" :maks-tagow="$question ? 3 : null" :pytanie="$question" />
        </div>

        {{-- `id` jest CELEM odnośnika z podsumowania błędów, a atrybuty ARIA
             wiążą błąd z grupą — patrz `x-blad-grupy`. --}}
        <fieldset class="border-0 p-0 mt-6" id="f-visibility"
                  @error('visibility') tabindex="-1" aria-invalid="true" aria-describedby="f-visibility-error" @enderror>
            <legend class="font-bold mb-3">Kto ma to widzieć?</legend>

            <div class="choice-grid">
                <label class="choice">
                    <input type="radio" name="visibility" value="public" @checked(old('visibility', $post->visibility) === 'public')>
                    <span>
                        <span class="choice-label">Wszyscy</span>
                        <span class="choice-help">Także osoby bez konta. Wpis może pojawić się w Google.</span>
                    </span>
                </label>

                <label class="choice">
                    <input type="radio" name="visibility" value="followers" @checked(old('visibility', $post->visibility) === 'followers')>
                    <span>
                        <span class="choice-label">Tylko osoby, które mnie obserwują</span>
                        <span class="choice-help">Nie trafi do Google ani do osób bez konta.</span>
                    </span>
                </label>

                <label class="choice">
                    <input type="radio" name="visibility" value="private" @checked(old('visibility', $post->visibility) === 'private')>
                    <span>
                        <span class="choice-label">Tylko ja</span>
                        <span class="choice-help">Twoje prywatne archiwum. Nikt inny tego nie zobaczy.</span>
                    </span>
                </label>
            </div>
            <x-blad-grupy name="visibility" />
        </fieldset>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz zmiany</button>
            <a class="btn btn-quiet" href="{{ $post->url() }}">Nie teraz</a>
        </div>
    </form>
</x-layout>
