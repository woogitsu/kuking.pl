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

    @if(auth()->id() === $post->author_id)
        <div class="danger-zone">
            <h2>Ten wpis jest Twój</h2>
            <p>Możesz go usunąć. Zniknie ze strony głównej i z Twojego archiwum.</p>
            <x-confirm-button
                :action="route('posts.destroy', $post)"
                label="Usuń ten wpis"
                question="Na pewno usunąć ten wpis? Tej operacji nie da się cofnąć samodzielnie." />
        </div>
    @endif

    <x-comment-thread :comments="$post->comments" :action="route('posts.comment', $post)" />
</x-layout>
