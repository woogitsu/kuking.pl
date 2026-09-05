<x-layout title="Start" :noindex="true">
    <h1>{{ $greeting }}</h1>

    <p style="margin-bottom:var(--spacing-6);">
        <a class="btn btn-primary" href="{{ route('posts.create') }}">Dodaj zdjęcie tego, co ugotowałeś</a>
    </p>

    {{-- Tablica stoi NAD feedem: dla osoby z pustym feedem to jest jedyna
         treść na tym ekranie, a dla pozostałych — powód, żeby kogoś nowego
         zaobserwować. --}}
    <x-kuking-board :people="$board['people']" :posts="$board['posts']" :notes="$board['notes']" />

    @if($showingDiscover)
        {{--
            Feed obserwowanych jest pusty. Nie pokazujemy pustki — pokazujemy
            świeże treści i konkretne osoby do obserwowania. Bez tego nowy
            użytkownik widzi biały ekran i nie wraca (docs/product/COLD_START.md).
        --}}
        <div class="notice">
            <strong>Twoja strona główna jest jeszcze pusta.</strong>
            Poniżej pokazujemy to, co ostatnio ugotowali inni. Kiedy zaczniesz kogoś
            obserwować, w tym miejscu będą pojawiać się jego wpisy.
        </div>

        <h2>Świeżo z Kuking</h2>
    @endif

    @if($posts->count() === 0)
        <x-empty-state title="Jeszcze nic tu nie ma" action="Dodaj pierwsze zdjęcie" :href="route('posts.create')">
            Zacznij od zdjęcia tego, co dziś ugotowałeś. Nie musi być ładne — ma być prawdziwe.
        </x-empty-state>
    @else
        <div class="stack">
            @foreach($posts as $post)
                <x-post-card :post="$post" />
            @endforeach
        </div>

        <x-show-more :paginator="$posts" />
    @endif
</x-layout>
