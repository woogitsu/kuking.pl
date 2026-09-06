@props(['recipe'])
<article class="card">
    <div class="flex gap-4 items-start">
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
        <div class="min-w-0">
            <h3 class="m-0 mb-2">
                <a href="{{ route('recipes.show', $recipe->slug) }}" style="color:var(--color-ink); text-decoration:none;">{{ $recipe->title }}</a>
            </h3>
            <p class="meta m-0 mb-2">{{ $recipe->attributionLine() }}</p>
            @if(($recipe->cooked_events_count ?? 0) > 0)
                <p class="m-0"><span class="badge badge-cooked">Ugotowane {{ $recipe->cooked_events_count }} ×</span></p>
            @endif
        </div>
    </div>
</article>
