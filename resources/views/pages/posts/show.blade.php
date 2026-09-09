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

    {{-- „Podziel się" stoi na STRONIE wpisu, a nie na karcie w feedzie.
         Wysyła się konkretny adres, więc miejscem tej akcji jest strona,
         którą ten adres otwiera. Na karcie w feedzie byłby to dwudziesty
         przycisk na ekranie i pierwszy, który myli „wyślij komuś"
         z „opublikuj u siebie". --}}
    <x-podziel-sie :tresc="$post" />

    @if(auth()->id() === $post->author_id)
        {{--
            Zachęta do kolejnego zdjęcia (COLD_START.md).

            „Po publikacji od razu proponujemy dodanie następnego — człowiek
            ma w telefonie czterdzieści zdjęć obiadów i jest w trybie »już
            wiem, jak to działa«". To jest jedyny moment, w którym opór przed
            publikacją jest zerowy, bo właśnie się udało.

            Stoi NAD strefą usuwania i wygląda inaczej niż ona: pierwszą
            rzeczą, którą autor widzi po publikacji, nie może być przycisk
            „Usuń ten wpis".
        --}}
        <div class="notice">
            @if($toPierwszyWpis ?? false)
                <strong>To Twój pierwszy wpis. Gratulacje.</strong>
                Masz pewnie w telefonie więcej zdjęć — teraz idzie najszybciej,
                bo już wiesz, jak to działa.
            @else
                <strong>Gotujesz dziś coś jeszcze?</strong>
                Dodanie kolejnego zdjęcia zajmuje mniej niż minutę.
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

    <x-comment-thread :comments="$komentarze" :ile="$komentarzyRazem" :action="route('posts.comment', $post)" />
</x-layout>
