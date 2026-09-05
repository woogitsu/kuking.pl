@props(['recipe'])
<article class="card">
    <div style="display:flex; gap:var(--spacing-4); align-items:flex-start;">
        @if($recipe->heroMedia)
            <a href="{{ route('recipes.show', $recipe->slug) }}" style="flex:none; width:120px;">
                {{-- `zoom` wyłączone: miniatura jest już linkiem do przepisu.
                     Zagnieżdżone `<a>` to nieprawidłowy HTML i psuje obsługę
                     klawiaturą. Tu i tak sensowniejsze jest przejście do
                     przepisu niż powiększenie zdjęcia. --}}
                <x-photo :media="$recipe->heroMedia" variant="thumb"
                         class="post-photo" :zoom="false" />
            </a>
        @endif
        <div style="min-width:0;">
            <h3 style="margin:0 0 var(--spacing-2);">
                <a href="{{ route('recipes.show', $recipe->slug) }}" style="color:var(--color-ink); text-decoration:none;">{{ $recipe->title }}</a>
            </h3>
            <p class="meta" style="margin:0 0 var(--spacing-2);">{{ $recipe->attributionLine() }}</p>
            @if(($recipe->cooked_events_count ?? 0) > 0)
                <p style="margin:0;"><span class="badge badge-cooked">Ugotowane {{ $recipe->cooked_events_count }} ×</span></p>
            @endif
        </div>
    </div>
</article>
