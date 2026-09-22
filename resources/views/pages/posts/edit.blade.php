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

    <form class="panel-formularza" method="POST" action="{{ route('posts.update', $post) }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="_tag_form_post_id" value="{{ $post->getKey() }}">
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
            <p class="field-help">Wpisz # i nazwę, na przykład #sernik. Tagi możesz też znaleźć poniżej.</p>
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

        <x-tagi-formularz :tag-names="$tagNames" :sugestie-tagow="$sugestieTagow" :maks-tagow="$question ? 3 : null" :pytanie="$question" />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz zmiany</button>
            <a class="btn btn-quiet" href="{{ $post->url() }}">Nie teraz</a>
        </div>
    </form>
</x-layout>
