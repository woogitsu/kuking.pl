<x-layout :title="$post->title" :description="\Illuminate\Support\Str::limit($post->body ?: $post->title, 155)"
          :noindex="$post->visibility !== 'public' || ! $post->isPublished()">
    @php $okruszki = \App\Support\Okruszki::dlaPytania($post); @endphp
    @if($post->visibility === 'public' && $post->isPublished())
        @php
            $questionSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'QAPage',
                'mainEntity' => [
                    '@type' => 'Question',
                    'name' => $post->title,
                    'text' => $post->body ?: $post->title,
                    'answerCount' => $komentarzyRazem,
                    {{--
                        Ta sama definicja odpowiedzi co licznik wyżej i lista
                        `/pytania` — `OdpowiedzNaPytanie::pasuje()` (#372).
                        Bez tego filtru dopisek autora pod własnym pytaniem
                        („Dodam, że mam piekarnik gazowy”) wyszedłby tu jako
                        `suggestedAnswer`, choć nikt na pytanie nie odpowiedział.
                    --}}
                    'suggestedAnswer' => $komentarze->getCollection()->filter(fn ($answer) => \App\Domain\Questions\OdpowiedzNaPytanie::pasuje($answer, $post->author_id))->map(fn ($answer) => [
                        '@type' => 'Answer',
                        'text' => $answer->body,
                        'url' => route('questions.show', ['post' => $post, 'komentarze' => $komentarze->currentPage()]).'#komentarz-'.$answer->id,
                        'datePublished' => $answer->created_at->toIso8601String(),
                    ])->values()->all(),
                ],
            ];
        @endphp
        <x-json-ld :data="$questionSchema" />
        {{-- Ta sama lista co widoczne okruszki niżej (#1033). --}}
        <x-json-ld :data="\App\Support\Okruszki::jsonLd($okruszki)" />
    @endif
    <x-okruszki :elementy="$okruszki" />
    <h1>{{ $post->title }}</h1>
    <x-post-card :post="$post" :show-question-title="false" />
    {{--
        DROGA DO „PYTANIA Z TAGIEM X” (#372). Chip w karcie prowadzi na ogólną
        stronę tagu (dania i pytania razem); filtr `/pytania?tag=` istniał,
        ale żaden link do niego nie prowadził. Osobna, jawnie podpisana
        nawigacja zamiast podmiany chipa: karta jest wspólna dla wszystkich
        ekranów, a etykieta musi odróżniać pytania od wszystkich wpisów.
        Tagi są już doładowane w `PostController::show`; bierzemy tylko aktywne.
    --}}
    @php
        $tagiPytania = $post->relationLoaded('tags')
            ? $post->tags->where('status', \App\Models\Tag::STATUS_ACTIVE)
            : collect();
    @endphp
    @if($tagiPytania->isNotEmpty())
        <section class="mb-6" aria-labelledby="pytania-z-tagiem">
            <h2 id="pytania-z-tagiem">Inne pytania na ten temat</h2>
            <nav class="chipsy mt-0" aria-label="Pytania z tagami tego pytania">
                @foreach($tagiPytania as $tagPytania)
                    <a class="chip" href="{{ route('questions.index', ['tag' => $tagPytania->slug]) }}">Pytania: {{ $tagPytania->name }}</a>
                @endforeach
            </nav>
        </section>
    @endif
    <x-podziel-sie :tresc="$post" />
    <x-comment-thread :comments="$komentarze" :ile="$komentarzyRazem"
                      :action="route('posts.comment', $post)" :answers="true" />
</x-layout>
