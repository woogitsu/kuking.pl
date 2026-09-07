{{--
    Strona tagu (D-021, zastępuje `pages/topics/show.blade.php`).

    Tag bez własnej strony jest etykietą, a nie miejscem. Cała wartość
    tagów, tak jak wcześniej Tematów, polega na tym, że osoba, która nikogo
    nie obserwuje, ma DOKĄD pójść (SOUL.md 4.7).

    Lista jest CHRONOLOGICZNA — żadnego „najpopularniejsze z tym tagiem".
    To ta sama decyzja co przy feedzie i z tego samego powodu: ranking
    zamienia dzielenie się jedzeniem w konkurs.
--}}
<x-layout :title="$tag->name">
    <p class="meta mb-2">
        <a href="{{ route('discover') }}">Świeżo z Kuking</a> · tag
    </p>

    <h1 class="mt-0">{{ $tag->name }}</h1>

    @auth
        {{-- Zwykły formularz, nie przycisk sterowany skryptem: bez JavaScriptu
             ma działać jedno i drugie (AGENTS.md §5). --}}
        <form class="mt-4" method="POST"
              action="{{ $obserwowany ? route('tags.unfollow', $tag) : route('tags.follow', $tag) }}">
            @csrf
            @if($obserwowany)
                @method('DELETE')
                <button class="btn btn-quiet" type="submit">Przestań obserwować ten tag</button>
                <span class="meta">Wpisy z tego tagu trafiają na Twoją stronę główną.</span>
            @else
                <button class="btn btn-primary" type="submit">Obserwuj ten tag</button>
                <span class="meta">Wpisy z tego tagu będą trafiać na Twoją stronę główną.</span>
            @endif
        </form>
    @endauth

    <div class="stack mt-6">
        @forelse($posts as $post)
            <x-post-card :post="$post" />
        @empty
            <x-empty-state title="Tu jeszcze nikt nic nie ugotował">
                <p>
                    Ten tag czeka na pierwszy wpis. Jeśli gotujesz coś, co tu pasuje,
                    możesz być pierwszą osobą.
                </p>
                <p class="mb-0">
                    <a class="btn btn-primary" href="{{ route('posts.create') }}">Dodaj zdjęcie</a>
                </p>
            </x-empty-state>
        @endforelse
    </div>

    @if($posts->hasPages())
        <x-show-more :paginator="$posts" />
    @endif
</x-layout>
