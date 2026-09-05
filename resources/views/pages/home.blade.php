<x-layout title="Start" :noindex="true">
    <h1>{{ $greeting }}</h1>

    <p style="margin-bottom:var(--spacing-6);">
        <a class="btn btn-primary" href="{{ route('posts.create') }}">Dodaj zdjęcie tego, co ugotowałeś</a>
    </p>

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

        @if($suggestedPeople->isNotEmpty())
            <section class="card" style="margin-bottom:var(--spacing-6);">
                <h2>Osoby, które tu gotują</h2>
                <div class="stack-tight">
                    @foreach($suggestedPeople as $person)
                        <div style="display:flex; align-items:center; gap:var(--spacing-3); flex-wrap:wrap;">
                            <x-avatar :user="$person" :size="48" />
                            <div style="flex:1; min-width:10rem;">
                                <a class="author-name" href="{{ route('profile.show', $person->profile->username) }}">{{ $person->displayName() }}</a>
                                <p class="meta" style="margin:0;">
                                    {{ $person->profile->speciality ?? 'Gotuje w Kuking' }}
                                    · {{ $person->posts_count }} {{ $person->posts_count === 1 ? 'wpis' : 'wpisów' }}
                                </p>
                            </div>
                            <form method="POST" action="{{ route('social.follow', $person->profile->username) }}">
                                @csrf
                                <button class="btn btn-secondary" type="submit">Obserwuj</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

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
