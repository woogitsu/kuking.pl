@php
    $isPublic = $post->visibility === 'public' && $post->isPublished();
@endphp
<x-layout
    :title="$post->author->displayName().' — wpis'"
    :description="\Illuminate\Support\Str::limit($post->body ?? 'Zdjęcie z Kuking', 155)"
    :noindex="! $isPublic"
    {{-- Wpis to najczęściej samo zdjęcie z podpisem — bez `og:image` link
         wklejony w Messengera nie pokazuje NICZEGO poza imieniem autora. --}}
    :image="$isPublic ? $post->media->first() : null"
    ogType="article">

    <x-post-card :post="$post" />

    @if($poprzedniWpis || $nastepnyWpis)
        {{--
            Kolejne zdjęcie tej samej osoby (Garnek.pl: „kolejne >" z miniaturą
            w prawej szynie) — jednym kliknięciem, bez powrotu na profil.

            Chronologicznie po `published_at`: „następny" to nowszy wpis, jak
            w archiwum. Widoczność liczy `App\Domain\Posts\SasiedniWpisAutora`
            (ten sam komplet zakresów co reszta serwisu), więc żaden z tych
            odnośników nie prowadzi do wpisu, którego oglądający nie ma prawa
            zobaczyć — a gdy sąsiada nie ma (pierwszy albo ostatni wpis),
            odpowiedni odnośnik po prostu nie istnieje.
        --}}
        <nav class="wpis-nawigacja-sasiedzi" aria-label="Inne wpisy tej osoby">
            @if($poprzedniWpis)
                <a class="wpis-nawigacja-sasiedzi-link wpis-nawigacja-sasiedzi-poprzedni"
                   href="{{ route('posts.show', $poprzedniWpis) }}">
                    @if($poprzedniWpis->media->first())
                        <img class="wpis-nawigacja-sasiedzi-miniatura"
                             src="{{ $poprzedniWpis->media->first()->url('thumb') }}"
                             alt="" width="64" height="64" loading="lazy">
                    @endif
                    <span class="wpis-nawigacja-sasiedzi-tekst">
                        <span aria-hidden="true">&larr;</span>
                        Poprzedni wpis
                    </span>
                </a>
            @endif

            @if($nastepnyWpis)
                <a class="wpis-nawigacja-sasiedzi-link wpis-nawigacja-sasiedzi-nastepny"
                   href="{{ route('posts.show', $nastepnyWpis) }}">
                    <span class="wpis-nawigacja-sasiedzi-tekst">
                        Następny wpis
                        <span aria-hidden="true">&rarr;</span>
                    </span>
                    @if($nastepnyWpis->media->first())
                        <img class="wpis-nawigacja-sasiedzi-miniatura"
                             src="{{ $nastepnyWpis->media->first()->url('thumb') }}"
                             alt="" width="64" height="64" loading="lazy">
                    @endif
                </a>
            @endif
        </nav>
    @endif

    {{-- „Podziel się" stoi na STRONIE wpisu, a nie na karcie w feedzie.
         Wysyła się konkretny adres, więc miejscem tej akcji jest strona,
         którą ten adres otwiera. Na karcie w feedzie byłby to dwudziesty
         przycisk na ekranie i pierwszy, który myli „wyślij komuś"
         z „opublikuj u siebie". --}}
    <x-podziel-sie :tresc="$post" />

    @if(auth()->id() === $post->author_id)
        {{--
            Zachęta do kolejnego zdjęcia (COLD_START.md).

            D-114: opisujemy drogę do formularza, bez założeń o zawartości
            telefonu i bez niezmierzonej obietnicy szybszego dodawania.

            Stoi NAD strefą usuwania i wygląda inaczej niż ona: pierwszą
            rzeczą, którą autor widzi po publikacji, nie może być przycisk
            „Usuń ten wpis".
        --}}
        <div class="notice">
            @if($toPierwszyWpis ?? false)
                <strong>To Twój pierwszy wpis.</strong>
                Kolejne zdjęcie dodasz przez ten sam formularz.
            @else
                {{-- BEZ „zajmuje mniej niż minutę": obietnica z miarą, której
                     nie mierzymy. Zostaje to, co jest prawdą niezależnie od
                     zasięgu — że kolejny wpis robi się tak samo jak ten. --}}
                <strong>Gotujesz dziś coś jeszcze?</strong>
                Kolejne dodasz tak samo — zdjęcie i kilka słów.
            @endif
            <p class="mb-0">
                <a class="btn btn-primary" href="{{ route('posts.create') }}">Dodaj kolejne zdjęcie</a>
            </p>
        </div>

        @if($post->media->count() > 1)
            {{--
                Wygląd i kolejność zdjęć (issue #92).

                Stoi TYLKO przy dwóch zdjęciach i większej liczbie — przy
                jednym nie ma czego ustawiać, a link do ekranu, który mówi
                „nie ma tu czego ustawiać", jest gorszy niż brak linku.

                To jest zarazem jedyna droga dla osoby BEZ JavaScriptu:
                w formularzu publikacji wybór wygląda inaczej, bo tam serwer
                nie zna jeszcze liczby zdjęć (patrz PostMediaController).
            --}}
            <div class="notice">
                <strong>Ten wpis ma kilka zdjęć.</strong>
                Możesz ustawić ich kolejność i wybrać, jak mają się wyświetlić:
                zwykle, karuzelą albo kolażem.
                <p class="mb-0">
                    <a class="btn btn-secondary" href="{{ route('posts.media.edit', $post) }}">Kolejność i wygląd zdjęć</a>
                </p>
            </div>
        @endif

        <div class="danger-zone">
            <h2>Ten wpis jest Twój</h2>
            <p>Możesz go usunąć. Zniknie ze strony głównej i z Twojego archiwum.</p>
            <x-confirm-button
                :action="route('posts.destroy', $post)"
                label="Usuń ten wpis"
                question="Na pewno usunąć ten wpis? Tej operacji nie da się cofnąć samodzielnie." />
        </div>
    @endif

    <x-zdejmij-z-urzedu :tresc="$post" typ="post" />

    <x-comment-thread :comments="$komentarze" :ile="$komentarzyRazem" :action="route('posts.comment', $post)" :can-comment="auth()->user()?->can('comment', $post) ?? false" />
</x-layout>
