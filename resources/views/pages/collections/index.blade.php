<x-layout title="Zeszyt" :noindex="true">
    <h1>Twój zeszyt</h1>
    <p style="margin-bottom:var(--spacing-5);">Przepisy, które zapisałaś na potem. Tylko Ty je widzisz, chyba że sama ustawisz inaczej.</p>

    @if($collections->isEmpty())
        <x-empty-state title="Zeszyt jest jeszcze pusty" action="Poszukaj przepisów" :href="route('discover')">
            Kiedy znajdziesz przepis, który chcesz zachować, kliknij przy nim „Zapisuję”.
            Trafi tutaj i zawsze go znajdziesz.
        </x-empty-state>
    @else
        <div class="stack">
            @foreach($collections as $collection)
                <article class="card">
                    <h2 style="margin-top:0;">
                        <a href="{{ route('collections.show', $collection) }}" style="color:var(--color-ink);">{{ $collection->name }}</a>
                    </h2>
                    <p class="meta" style="margin:0;">
                        {{ $collection->recipes_count }} {{ $collection->recipes_count === 1 ? 'przepis' : 'przepisów' }}
                        · {{ $collection->isPublic() ? 'Widoczny dla wszystkich' : 'Tylko dla Ciebie' }}
                    </p>
                    @if($collection->description)
                        <p style="margin-top:var(--spacing-3);">{{ $collection->description }}</p>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    <details class="card" style="margin-top:var(--spacing-8);">
        <summary class="btn btn-secondary" style="display:inline-flex;">Załóż nowy zeszyt</summary>
        <form method="POST" action="{{ route('collections.store') }}" style="margin-top:var(--spacing-4);">
            @csrf
            <x-field name="name" label="Nazwa zeszytu" required placeholder="Na święta" />
            <x-field name="description" label="Krótki opis" type="textarea" :rows="2" />
            <fieldset style="border:0; padding:0; margin-top:var(--spacing-4);">
                <legend style="font-weight:700; margin-bottom:var(--spacing-3);">Kto ma widzieć ten zeszyt?</legend>
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
            <button class="btn btn-primary" type="submit" style="margin-top:var(--spacing-4);">Załóż zeszyt</button>
        </form>
    </details>
</x-layout>
