<x-layout title="Zeszyt" :noindex="true">
    {{-- PRAWA SZYNA (issue #205): rzeczy odłożone ostatnio, żeby nie trzeba
         było pamiętać, do którego zeszytu poszły. Uzasadnienie treści:
         `szyna-ostatnio-zapisane`. Slot stoi na górze pliku, a w gotowym
         dokumencie renderuje się PO `<main>` — Blade wstawia go tam, gdzie
         slot stoi w LAYOUCIE, więc kolejność `Tab` się nie zmienia. --}}
    <x-slot:rail>
        <x-szyna-ostatnio-zapisane :pozycje="$ostatnioZapisane" />
    </x-slot:rail>

    <h1>Twój zeszyt</h1>
    {{-- „Przepisy i wpisy", nie same przepisy: od 6 września Zeszyt przyjmuje
         też cudze wpisy (migracja `collection_items_accept_posts`, przycisk
         „Zapisuję" na karcie wpisu). Ten akapit i pusty stan niżej mówiły
         dalej o samych przepisach — obietnica węższa niż produkt, i akurat
         w tę stronę, w którą człowiek nie sprawdzi, bo nie spróbuje. --}}
    <p class="mb-5">Przepisy i wpisy, które chcesz zachować na potem. Tylko Ty je widzisz, chyba że ustawisz inaczej.</p>

    {{-- Błąd przy polu ORAZ w podsumowaniu (docs/UX_50_PLUS.md). Bez tego
         komunikat „Masz już zeszyt o tej nazwie” nie miał gdzie się pokazać:
         jedyny formularz na tej stronie siedzi w zwiniętym <details>. --}}
    <x-error-summary />

    @if($collections->isEmpty())
        <x-empty-state title="Zeszyt jest jeszcze pusty" action="Poszukaj przepisów" :href="route('discover')">
            Kiedy znajdziesz przepis albo czyjeś danie, które chcesz zachować,
            kliknij przy nim „Zapisuję”. Trafi tutaj i zawsze do niego wrócisz.
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
    <details class="panel-formularza mt-8" {{ $errors->any() ? 'open' : '' }}>
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
