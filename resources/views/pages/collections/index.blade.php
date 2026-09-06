<x-layout title="Zeszyt" :noindex="true">
    <h1>Twój zeszyt</h1>
    <p class="mb-5">Przepisy, które chcesz zachować na potem. Tylko Ty je widzisz, chyba że ustawisz inaczej.</p>

    {{-- Błąd przy polu ORAZ w podsumowaniu (docs/UX_50_PLUS.md). Bez tego
         komunikat „Masz już zeszyt o tej nazwie” nie miał gdzie się pokazać:
         jedyny formularz na tej stronie siedzi w zwiniętym <details>. --}}
    <x-error-summary />

    @if($collections->isEmpty())
        <x-empty-state title="Zeszyt jest jeszcze pusty" action="Poszukaj przepisów" :href="route('discover')">
            Kiedy znajdziesz przepis, który chcesz zachować, kliknij przy nim „Zapisuję”.
            Trafi tutaj i zawsze go znajdziesz.
        </x-empty-state>
    @else
        <div class="stack">
            @foreach($collections as $collection)
                <article class="card">
                    <h2 class="mt-0">
                        <a class="text-ink" href="{{ route('collections.show', $collection) }}">{{ $collection->name }}</a>
                    </h2>
                    <p class="meta m-0">
                        {{-- Odmiana przez App\Support\Odmiana: dwustanowa
                             pisała „3 przepisów" (B5). --}}
                        {{ $collection->recipes_count }} {{ \App\Support\Odmiana::rzeczownik($collection->recipes_count, 'przepis', 'przepisy', 'przepisów') }}
                        @if(($collection->posts_count ?? 0) > 0)
                            · {{ $collection->posts_count }} {{ \App\Support\Odmiana::rzeczownik($collection->posts_count, 'wpis', 'wpisy', 'wpisów') }}
                        @endif
                        · {{ $collection->isPublic() ? 'Widoczny dla wszystkich' : 'Tylko dla Ciebie' }}
                    </p>
                    @if($collection->description)
                        <p class="mt-3">{{ $collection->description }}</p>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    {{-- Po nieudanej walidacji formularz zostaje ROZWINIĘTY — inaczej człowiek
         wraca na stronę, na której nic się nie stało, a jego tekst jest
         schowany pod zwiniętym „Załóż nowy zeszyt”. --}}
    <details class="card mt-8" {{ $errors->any() ? 'open' : '' }}>
        <summary class="btn btn-secondary inline-flex">Załóż nowy zeszyt</summary>
        <form class="mt-4" method="POST" action="{{ route('collections.store') }}">
            @csrf
            <x-field name="name" label="Nazwa zeszytu" required placeholder="Na święta" />
            <x-field name="description" label="Krótki opis" type="textarea" :rows="2" />
            <fieldset class="border-0 p-0 mt-4">
                <legend class="font-bold mb-3">Kto ma widzieć ten zeszyt?</legend>
                <div class="choice-grid">
                    <label class="choice">
                        <input type="radio" name="visibility" value="private" checked>
                        <span class="choice-label">Tylko ja</span>
                    </label>
                    <label class="choice">
                        <input type="radio" name="visibility" value="public">
                        <span class="choice-label">Wszyscy</span>
                    </label>
                </div>
            </fieldset>
            <button class="btn btn-primary mt-4" type="submit">Załóż zeszyt</button>
        </form>
    </details>
</x-layout>
