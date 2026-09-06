<x-layout :title="$collection->name" :noindex="! $collection->isPublic()">
    <h1>{{ $collection->name }}</h1>
    @if($collection->description)
        <p>{{ $collection->description }}</p>
    @endif
    <p class="meta mb-5">
        {{ $collection->isPublic() ? 'Ten zeszyt widzą wszyscy.' : 'Ten zeszyt widzisz tylko Ty.' }}
    </p>

    @if($recipes->count() === 0)
        <x-empty-state title="W tym zeszycie nic jeszcze nie ma" action="Poszukaj przepisów" :href="route('discover')" />
    @else
        <div class="stack">
            @foreach($recipes as $recipe)
                <x-recipe-card :recipe="$recipe" />
            @endforeach
        </div>
        <div class="mt-6">{{ $recipes->links() }}</div>
    @endif

    @if(auth()->id() === $collection->owner_id && ! $collection->is_default)
        <div class="danger-zone">
            <x-confirm-button
                :action="route('collections.destroy', $collection)"
                label="Usuń ten zeszyt"
                question="Usunąć ten zeszyt? Same przepisy zostaną — znikną tylko z tego zeszytu." />
        </div>
    @endif
</x-layout>
