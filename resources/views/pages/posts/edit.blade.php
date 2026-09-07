{{--
    Edycja wpisu: tekst, widoczność, temat.

    ZDJĘCIA CELOWO NIE SĄ TU — mają już swój ekran, „Zdjęcia w tym wpisie"
    (`posts.media.edit`, `PostMediaController`). Powielenie tego pola tutaj
    dałoby dwie drogi do tego samego stanu (AGENTS.md §4).

    Wzorzec pól i błędów zerżnięty z `pages/posts/create.blade.php`: te same
    trzy pola, te same komunikaty, żeby autor nie uczył się dwóch formularzy
    do tej samej rzeczy.
--}}
<x-layout title="Edytuj wpis" :noindex="true">
    <h1>Edytuj wpis</h1>

    <x-error-summary />

    <form class="card" method="POST" action="{{ route('posts.update', $post) }}">
        @csrf
        @method('PUT')

        <x-field
            name="body"
            label="Napisz kilka słów"
            type="textarea"
            :rows="5"
            :value="$post->body"
            help="Na przykład: „Rosół na niedzielę, z kaczki od sąsiada. Wyszedł złoty.”"
        />

        <fieldset class="border-0 p-0 mt-6">
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
            @error('visibility')<span class="field-error">{{ $message }}</span>@enderror
        </fieldset>

        {{-- Temat — opcjonalny, jak w formularzu publikacji (issue #31). --}}
        <details class="temat-wybor mt-6" @if(old('topic_id', $post->topic_id)) open @endif>
            <summary>Dodaj temat (nieobowiązkowo)</summary>

            <p class="field-help mt-3">
                Temat pomaga innym znaleźć Twój wpis, a Tobie — trafić na ludzi,
                którzy gotują to samo. Możesz to pominąć.
            </p>

            <label for="topic_id">Temat</label>
            <select id="topic_id" name="topic_id">
                <option value="">— bez tematu —</option>
                @foreach($topics as $topic)
                    <option value="{{ $topic->id }}" @selected(old('topic_id', $post->topic_id) === $topic->id)>{{ $topic->name }}</option>
                @endforeach
            </select>
            @error('topic_id')<span class="field-error">{{ $message }}</span>@enderror
        </details>

        <x-tagi-formularz :tag-names="$tagNames" :sugestie-tagow="$sugestieTagow" />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz zmiany</button>
            <a class="btn btn-quiet" href="{{ route('posts.show', $post) }}">Nie teraz</a>
        </div>
    </form>
</x-layout>
