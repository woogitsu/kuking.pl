<x-layout :title="$post->title" :description="\Illuminate\Support\Str::limit($post->body ?: $post->title, 155)"
          :noindex="$post->visibility !== 'public' || ! $post->isPublished()">
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
                    'suggestedAnswer' => $komentarze->getCollection()->filter(fn ($answer) => $answer->getAttribute('body_removed_at') === null)->map(fn ($answer) => [
                        '@type' => 'Answer',
                        'text' => $answer->body,
                        'url' => route('questions.show', ['post' => $post, 'komentarze' => $komentarze->currentPage()]).'#komentarz-'.$answer->id,
                        'datePublished' => $answer->created_at->toIso8601String(),
                    ])->values()->all(),
                ],
            ];
        @endphp
        <x-json-ld :data="$questionSchema" />
    @endif
    <p><a href="{{ route('questions.index') }}">Poradźcie — pytania do innych</a></p>
    <h1>{{ $post->title }}</h1>
    <x-post-card :post="$post" :show-question-title="false" />
    <x-podziel-sie :tresc="$post" />
    <x-comment-thread :comments="$komentarze" :ile="$komentarzyRazem"
                      :action="route('posts.comment', $post)" :answers="true" />
</x-layout>
