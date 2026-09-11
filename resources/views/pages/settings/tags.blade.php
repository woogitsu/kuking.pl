{{--
    „Twoje tagi" — jeden ekran z całą listą (D-021, zastępuje
    `pages/settings/topics.blade.php`).

    Lista to SUMA tagów już obserwowanych i tagów promowanych (D-021, „tag
    promowany — lista gospodarza") — patrz komentarz w
    `TagFollowController::edit()`, dlaczego to musi być suma, nie sama lista
    promowana: wszechświat tagów nie jest zamknięty.

    Zwykły formularz, bez JavaScriptu: zaznaczenie i „Zapisz".
--}}
<x-layout title="Twoje tagi" :noindex="true">
    <h1>Twoje tagi</h1>

    <p class="lead">
        Z tych tagów budujemy Twoją stronę główną, dopóki nikogo nie
        obserwujesz. Kiedy zaczniesz obserwować ludzi, ich wpisy będą
        ważniejsze niż tagi — i to one pojawią się na górze.
    </p>

    @if($tags->isEmpty())
        <x-empty-state
            title="Nie obserwujesz jeszcze żadnego tagu"
            action="Zobacz wszystkie tagi"
            :href="route('tags.index')">
            <p class="mb-0">
                Wybierz tag i kliknij „Obserwuj ten tag" — albo wróć tutaj,
                gdy gospodarz doda pierwsze propozycje.
            </p>
        </x-empty-state>
    @else
        <form method="POST" action="{{ route('settings.tags.update') }}">
            @csrf
            @method('PUT')

            <div class="choice-grid">
                @foreach($tags as $tag)
                    <label class="choice">
                        <input type="checkbox" name="tags[]" value="{{ $tag->getKey() }}"
                               @checked(in_array($tag->getKey(), $followed, true))>
                        <span class="choice-label">{{ $tag->name }}</span>
                    </label>
                @endforeach
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Zapisz</button>
                <a class="btn btn-quiet" href="{{ route('home') }}">Wróć na stronę główną</a>
            </div>
        </form>
    @endif

    {{-- Spis „Wszystkie ustawienia" w prawej szynie, nie pod formularzem —
         uzasadnienie i próg szerokości: components/ustawienia-nawigacja.blade.php. --}}
    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="tags" />
    </x-slot:rail>
</x-layout>
