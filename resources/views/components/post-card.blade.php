{{--
    Karta wpisu (UI kit v2, etap B — ekrany 01_desktop_feed i 05_mobile_feed).

    UKŁAD Z PACZKI WŁAŚCICIELA
    Nagłówek (kto i kiedy) → kilka słów → zdjęcie → pasek akcji oddzielony
    kreską. Ta kolejność jest z mockupów i ma powód: człowiek najpierw widzi,
    KTO gotował, a dopiero potem CO. Kuking jest społecznością ludzi,
    nie galerią zdjęć (docs/PRODUCT.md).

    „UGOTOWAŁEM" JEST GŁÓWNĄ AKCJĄ, NIE OZDOBNIKIEM
    Gdy wpis powstał z przepisu, „Ugotowałem" stoi PIERWSZE i jako jedyne ma
    pełny kolor marki. To jest najcenniejszy sygnał w całym serwisie — realne
    wykonanie przepisu przez inną osobę — i zawsze powiadamia autora
    (AGENTS.md §1). Komentarz jest obok, jako akcja drugiego planu.

    BEZ JAVASCRIPTU I BEZ NAJEŻDŻANIA MYSZĄ
    Wszystkie akcje to zwykłe odnośniki. Nic się nie odsłania po najechaniu
    ani po przesunięciu palcem — dla części naszych użytkowników to jedyna
    droga do funkcji (AGENTS.md §5).
--}}
@props(['post'])
@php $author = $post->author; @endphp
<article class="card post-card">
    <div class="post-card-head">
        <a href="{{ route('profile.show', $author->profile->username) }}" aria-hidden="true" tabindex="-1">
            <x-avatar :user="$author" :size="52" />
        </a>
        <div class="min-w-0">
            <a class="author-name" href="{{ route('profile.show', $author->profile->username) }}">{{ $author->displayName() }}</a>
            <p class="meta m-0">
                <a href="{{ $post->url() }}" style="color:inherit;">
                    <time datetime="{{ $post->published_at?->toIso8601String() }}">{{ \App\Support\Czas::dataLubNic($post->published_at, 'j F Y, H:i') }}</time>
                </a>
                {{-- Widoczność przy dacie, tak jak w kicie (ekran 01: „2 godz.
                     temu · publicznie"). Także dla wpisu publicznego: autor ma
                     wiedzieć jednym spojrzeniem, kto to widzi, a nie dopiero
                     po wejściu w edycję. --}}
                @if($post->visibility === 'followers')
                    · <span class="badge">Tylko dla obserwujących</span>
                @elseif($post->visibility === 'private')
                    · <span class="badge">Tylko dla mnie</span>
                @else
                    · <span>publicznie</span>
                @endif
            </p>
        </div>

        @auth
            {{--
                MENU „…" (UI kit v2, ekran 01).

                `<details>`, nie przycisk sterowany skryptem: menu, które bez
                JavaScriptu nie otwiera się wcale, jest ozdobą udającą przycisk
                (AGENTS.md §5). Ten sam wzorzec co przy potwierdzeniach
                kasowania.

                W środku siedzą rzeczy, po które NIE sięga się odruchowo —
                dlatego zeszły z paska akcji pod spodem: tam zostają tylko
                „Ugotowałem" i komentarze.
            --}}
            <details class="post-card-menu">
                <summary aria-label="Więcej przy tym wpisie">
                    <span aria-hidden="true">···</span>
                </summary>
                <div class="post-card-menu-tresc">
                    <a href="{{ $post->url() }}">Otwórz wpis</a>
                    @if(auth()->id() === $post->author_id)
                        @if($post->media->count() > 1)
                            <a href="{{ route('posts.media.edit', $post) }}">Zdjęcia w tym wpisie</a>
                        @endif
                    @else
                        <a href="{{ route('reports.create', ['type' => 'post', 'id' => $post->getKey()]) }}">Zgłoś ten wpis</a>
                    @endif
                </div>
            </details>
        @endauth
    </div>

    @if($post->body)
        <div class="post-card-body">{{ $post->body }}</div>
    @endif

    {{--
        TRZY SPOSOBY POKAZANIA ZDJĘĆ, JEDNA DECYZJA AUTORA (issue #92)

        Wpis może mieć do sześciu zdjęć i to autor wybiera, jak mają się
        pokazać: zwykle (jedno pod drugim), karuzelą albo kolażem. Nie jest to
        ustawienie widza ani zgadywanie po liczbie zdjęć — człowiek, który
        fotografował danie z czterech stron, wie lepiej niż my, czy chce
        pokazać je obok siebie, czy po kolei.

        `trybWyswietlaniaZdjec()` liczy się na modelu, nie tutaj: przy jednym
        zdjęciu wszystkie trzy tryby dają ten sam widok, a wpis może stracić
        zdjęcia długo po wyborze autora. Widok pyta o gotową odpowiedź.

        Wpis zapisany przed tą zmianą ma `display_mode = 'normal'` z wartości
        domyślnej w bazie, więc trafia w tę samą gałąź co zawsze i wygląda
        dokładnie jak wczoraj — bez migracji danych.
    --}}
    @if($post->media->isNotEmpty())
        @switch($post->trybWyswietlaniaZdjec())
            @case(\App\Models\Post::DISPLAY_CAROUSEL)
                <x-karuzela-zdjec :post="$post" />
                @break

            @case(\App\Models\Post::DISPLAY_COLLAGE)
                <x-kolaz-zdjec :post="$post" />
                @break

            @default
                <div class="photo-grid">
                    @foreach($post->media as $media)
                        <x-photo :media="$media" />
                    @endforeach
                </div>
        @endswitch
    @endif

    @if($post->recipe)
        <p class="post-card-recipe">
            <span class="badge badge-cooked">Z przepisu</span>
            <a href="{{ route('recipes.show', $post->recipe->slug) }}">{{ $post->recipe->title }}</a>
        </p>
    @endif

    <div class="post-card-actions">
        @auth
            @if($post->recipe)
                {{-- Pierwsza pozycja i jedyny przycisk w kolorze marki.
                     Kolejność w kodzie = kolejność dla klawiatury i czytnika
                     ekranu, więc waga wizualna i waga w nawigacji się zgadzają. --}}
                <a class="btn btn-primary" href="{{ route('cooked.create', $post->recipe->slug) }}">
                    <x-ikona nazwa="chef" :rozmiar="22" />
                    Ugotowałem
                </a>
            @endif
        @endauth

        <a class="btn btn-secondary" href="{{ $post->url() }}">
            <x-ikona nazwa="chat" :rozmiar="22" />
            @if(($post->comments_count ?? 0) > 0)
                Komentarze ({{ $post->comments_count }})
            @else
                Napisz komentarz
            @endif
        </a>

        {{-- „Zgłoś" przeniosło się do menu „…" nad wpisem (UI kit v2).
             Pasek akcji ma nieść to, po co człowiek tu przyszedł. --}}
    </div>
</article>
