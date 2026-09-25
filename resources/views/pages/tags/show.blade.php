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

    /*
     * DLACZEGO SPIS TAGÓW MOŻE MÓWIĆ „0 WPISÓW", GDY WIDZĘ TU SWÓJ WPIS (#681).
     *
     * Właściciel zgłosił: dodał wpis z tagiem, a `/tagi` pokazało „0 wpisów".
     * Liczba jest PRAWIDŁOWA i celowa — D-087 liczy tylko wpisy widoczne dla
     * wszystkich, żeby ta sama liczba znaczyła to samo dla każdego i dała się
     * policzyć jednym zapytaniem dla całej strony. Ale wpis prywatny albo
     * „tylko dla obserwujących" widzi na tej stronie jego autor, więc bez
     * jednego zdania liczba wygląda jak usterka.
     *
     * Liczymy TYLKO wpisy zalogowanej osoby z tej strony wyników i TYLKO te,
     * które nie wchodzą do liczby publicznej. Bez dodatkowego zapytania:
     * `$posts` jest już wczytane, a `visibility` i `status` są w kolumnach.
     * Nie mówimy niczego o cudzych wpisach — o tym, czego nie widać, nie
     * informujemy nawet półsłówkiem.
     */
    $wlasneNiepubliczneWpisy = auth()->check()
        ? $posts->getCollection()
            ->filter(fn ($post) => $post->author_id === auth()->id()
                && ($post->visibility !== \App\Models\Post::VISIBILITY_PUBLIC
                    || $post->status !== \App\Models\Post::STATUS_PUBLISHED))
        : collect();
    $wlasneNiepubliczne = $wlasneNiepubliczneWpisy->count();

    /*
     * „WIDZISZ TYLKO TY" WOLNO NAPISAĆ TYLKO O WPISIE PRYWATNYM (#1392).
     *
     * Wpis „tylko dla obserwujących" widzą też obserwujący autora
     * (`PostPolicy::view()`), więc wspólne zdanie dla obu widoczności
     * wprowadzało autora w błąd co do prywatności jego treści. Przy grupie
     * mieszanej mówimy neutralnie „nie są widoczne dla wszystkich".
     */
    $wszystkiePrywatne = $wlasneNiepubliczne > 0 && $wlasneNiepubliczneWpisy
        ->every(fn ($post) => $post->visibility === \App\Models\Post::VISIBILITY_PRIVATE);
    $wszystkieDlaObserwujacych = $wlasneNiepubliczne > 0 && $wlasneNiepubliczneWpisy
        ->every(fn ($post) => $post->visibility === \App\Models\Post::VISIBILITY_FOLLOWERS
            && $post->status === \App\Models\Post::STATUS_PUBLISHED);
    $n = $wlasneNiepubliczne;
    $mnogaOd2Do4 = $n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14);
    $twojeWpisy = $n === 1 ? 'Jeden Twój wpis' : ($mnogaOd2Do4 ? "{$n} Twoje wpisy" : "{$n} Twoich wpisów");
    $niewidoczne = $n === 1 ? 'nie jest widoczny' : ($mnogaOd2Do4 ? 'nie są widoczne' : 'nie jest widocznych');
@endphp
<x-layout :title="$tag->name" :description="\Illuminate\Support\Str::limit($opisTagu, 155)" :noindex-follow="! $indeksowalny">
    <p class="meta mb-2">
        <a href="{{ route('discover') }}">Świeżo z <x-kuking-word /></a> ·
        <a href="{{ route('tags.index') }}">wszystkie tagi</a>
    </p>

    <section class="tag-welcome" aria-labelledby="tag-title">
    <div class="tag-welcome-copy">
    <h1 class="mt-0" id="tag-title">{{ $tag->name }}</h1>

    <p class="text-lead">{{ $tagNote ?: 'Zobacz, co gotują inni. Dodaj zdjęcie ze swojej kuchni i kilka słów.' }}</p>

    <p><x-tag-public-stats :stats="$publicStats" /></p>

    @if($wlasneNiepubliczne > 0)
        {{-- Tekst PODSTAWOWY, nie pomocniczy: to jest odpowiedź na pytanie
             „dlaczego w spisie jest zero", a nie ozdobnik. `.meta` ma 16 px,
             a twardy standard UX 50+ z AGENTS.md §7 to minimum 18 px.
             Ten sam wniosek padł w review #681 przy podpisie autora zdjęcia. --}}
        <p data-wlasne-niepubliczne>
            @if($wszystkiePrywatne)
                {{ $twojeWpisy }} z tym tagiem widzisz tylko Ty.
            @elseif($wszystkieDlaObserwujacych)
                {{ $twojeWpisy }} z tym tagiem widzą tylko osoby, które Cię obserwują, i Ty.
            @else
                {{ $twojeWpisy }} z tym tagiem {{ $niewidoczne }} dla wszystkich.
            @endif
            W spisie tagów liczymy wpisy widoczne dla wszystkich, więc
            {{ $n === 1 ? 'ten wpis się tam nie liczy' : 'te wpisy się tam nie liczą' }}.
            Możesz to zmienić w ustawieniach widoczności wpisu.
        </p>
    @endif

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

    <p class="mb-0">
        <a class="btn btn-primary" href="{{ route('posts.create', ['tag' => $tag->slug]) }}">Dodaj wpis z tym tagiem</a>
    </p>
    </div>
    @if($collage->isNotEmpty())
        <div class="tag-welcome-photos">
            <x-tag-collage :photos="$collage" />
            <p class="meta">Zdjęcia z ostatnich publicznych wpisów. Wybierz zdjęcie, żeby zobaczyć wpis.</p>
        </div>
    @endif
    </section>

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
                    <a class="btn btn-primary" href="{{ route('posts.create', ['tag' => $tag->slug]) }}">Dodaj zdjęcie</a>
                </p>
            </x-empty-state>
        @endforelse
    </div>

    @if($posts->hasPages())
        <x-show-more :paginator="$posts" />
    @endif
</x-layout>
