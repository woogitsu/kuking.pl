{{--
    Strona tagu (D-021, zastępuje `pages/topics/show.blade.php`).

    Tag bez własnej strony jest etykietą, a nie miejscem. Cała wartość
    tagów, tak jak wcześniej Tematów, polega na tym, że osoba, która nikogo
    nie obserwuje, ma DOKĄD pójść (SOUL.md 4.7).

    Lista jest CHRONOLOGICZNA — żadnego „najpopularniejsze z tym tagiem".
    To ta sama decyzja co przy feedzie i z tego samego powodu: ranking
    zamienia dzielenie się jedzeniem w konkurs.
--}}
@php
    /*
     * Meta description (issue #191) — Lighthouse mierzył tę stronę na
     * SEO 92/100 zamiast 100, bo `<x-layout>` nie dostawał `description`
     * w ogóle (renderuje znacznik TYLKO, gdy coś jest przekazane).
     *
     * Treść liczymy tutaj, nie w kontrolerze: `$posts` (wynik paginacji,
     * już przefiltrowany przez `widoczneDla($widz)`) jest jedynym miejscem,
     * które zna liczbę wpisów WIDOCZNYCH DLA GOŚCIA — a to jest dokładnie
     * to, co zobaczy robot Google (odwiedza jako anonim, `$widz === null`).
     * Kontroler zostaje cienki (AGENTS.md §4): to jest czyste formatowanie
     * tekstu, nie reguła domenowa.
     */
    $liczbaWpisow = $posts->total();
    $formaWpis = \App\Support\Odmiana::rzeczownik($liczbaWpisow, 'wpis', 'wpisy', 'wpisów');

    $opisTagu = $liczbaWpisow > 0
        ? "{$liczbaWpisow} {$formaWpis} z tagiem „{$tag->name}” w Kuking — zobacz, co ugotowali inni."
        : "Tag „{$tag->name}” w Kuking czeka na pierwszy wpis — dodaj go i bądź pierwszą osobą.";
@endphp
<x-layout :title="$tag->name" :description="\Illuminate\Support\Str::limit($opisTagu, 155)">
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
