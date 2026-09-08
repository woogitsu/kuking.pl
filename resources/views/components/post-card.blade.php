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
                <a href="{{ $post->url() }}" class="link-jak-tekst">
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
                {{-- Plakietka cicha „konto przykładowe" (D-032)
                     stoi w TYM SAMYM wierszu metadanych, po ostatniej
                     kropce — nie osobną linią pod nazwą autora jak dawniej.
                     Kropkę-separator rysuje sam komponent. --}}
                <x-konto-przykladowe :user="$author" />
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
                    {{-- `@can`, a nie `auth()->id() === $post->author_id`: reguła
                         „kto może zmieniać ten wpis" ma jedno miejsce
                         (`PostPolicy::update`), a nie kopię w widoku obok kopii
                         w kontrolerze (AGENTS.md §7). Dziś obie mówiły to samo;
                         jutro ktoś poprawi jedną i menu pokaże „Edytuj" komuś,
                         kto po kliknięciu dostanie 403. --}}
                    @can('update', $post)
                        <a href="{{ route('posts.edit', $post) }}">Edytuj wpis</a>
                        @if($post->media->count() > 1)
                            <a href="{{ route('posts.media.edit', $post) }}">Zdjęcia w tym wpisie</a>
                        @endif
                        {{--
                            „Usuń wpis" — akcja destrukcyjna, odsunięta od
                            zwykłych akcji i wymagająca potwierdzenia
                            (AGENTS.md §5). `.danger-zone` daje odstęp
                            i kreskę, `x-confirm-button` to ten sam wzorzec
                            potwierdzenia bez JavaScriptu co na stronie wpisu
                            (`pages/posts/show.blade.php`).
                        --}}
                        <div class="danger-zone">
                            <x-confirm-button
                                :action="route('posts.destroy', $post)"
                                label="Usuń wpis"
                                question="Na pewno usunąć ten wpis? Tej operacji nie da się cofnąć samodzielnie." />
                        </div>
                    @else
                        <a href="{{ route('reports.create', ['type' => 'post', 'id' => $post->getKey()]) }}">Zgłoś ten wpis</a>
                    @endcan
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

        @auth
            {{--
                „ZAPISUJĘ" (decyzja właściciela, `docs/DECISIONS.md` D-036).

                Do tej zmiany przycisk nosił „Zapisz", a przycisk zapisu
                przepisu (`recipes/show.blade.php`) już wtedy mówił
                „Zapisuję" — dwie nazwy dla jednej czynności na ekranie.
                `BRAND_EXTENDED.md` §3 zabrania synonimów: nazwa funkcji
                jest jedna. Ujednolicone na „Zapisuję" wszędzie.

                Zeszyt przyjmuje od tej zmiany także wpisy. To jest inna
                potrzeba niż zapisanie przepisu: zapisany przepis znaczy „chcę
                to ugotować i mam listę składników", a zapisane zdjęcie —
                „chcę kiedyś zrobić coś TAKIEGO". Przy wpisie żadnego przepisu
                zwykle nie ma.

                DLACZEGO ZAWSZE „ZAPISUJĘ", A NIE „ZAPISANO"
                Sprawdzenie stanu dla każdej karty to jedno zapytanie na wpis
                — czyli dwadzieścia zapytań na przewinięcie feedu. Zapis jest
                za to bezpieczny przy powtórzeniu: drugie kliknięcie daje
                dokładnie ten sam skutek co pierwsze i nie przesuwa pozycji
                w zeszycie (SavePostToCollection). Wyjąć z zeszytu można
                w samym zeszycie.
            --}}
            <form method="POST" action="{{ route('collections.save-post', $post) }}">
                @csrf
                <button class="btn btn-secondary" type="submit">
                    <x-ikona nazwa="book" :rozmiar="22" />
                    Zapisuję
                </button>
            </form>
        @endauth

        {{-- „Zgłoś" przeniosło się do menu „…" nad wpisem (UI kit v2).
             Pasek akcji ma nieść to, po co człowiek tu przyszedł. --}}
    </div>
</article>
