@props(['post'])
@php $author = $post->author; @endphp
<article class="card post-card">
    <div class="post-card-head">
        <a href="{{ route('profile.show', $author->profile->username) }}" aria-hidden="true" tabindex="-1">
            <x-avatar :user="$author" :size="52" />
        </a>
        <div style="min-width:0;">
            <a class="author-name" href="{{ route('profile.show', $author->profile->username) }}">{{ $author->displayName() }}</a>
            <p class="meta" style="margin:0;">
                <a href="{{ $post->url() }}" style="color:inherit;">
                    <time datetime="{{ $post->published_at?->toIso8601String() }}">{{ $post->published_at?->translatedFormat('j F Y, H:i') }}</time>
                </a>
                @if($post->visibility === 'followers')
                    · <span class="badge">Tylko dla obserwujących</span>
                @elseif($post->visibility === 'private')
                    · <span class="badge">Tylko dla mnie</span>
                @endif
            </p>
        </div>
    </div>

    @if($post->media->isNotEmpty())
        <div class="photo-grid">
            @foreach($post->media as $media)
                <x-photo :media="$media" />
            @endforeach
        </div>
    @endif

    @if($post->body)
        <div class="post-card-body">{{ $post->body }}</div>
    @endif

    @if($post->recipe)
        <p style="padding:0 var(--spacing-5);">
            <span class="badge badge-cooked">Z przepisu</span>
            <a href="{{ route('recipes.show', $post->recipe->slug) }}">{{ $post->recipe->title }}</a>
        </p>
    @endif

    <div class="post-card-actions">
        <a class="btn btn-secondary" href="{{ $post->url() }}">
            @if(($post->comments_count ?? 0) > 0)
                Komentarze ({{ $post->comments_count }})
            @else
                Napisz komentarz
            @endif
        </a>
        @auth
            @if(auth()->id() !== $post->author_id)
                <a class="btn btn-quiet" href="{{ route('reports.create', ['type' => 'post', 'id' => $post->getKey()]) }}">Zgłoś</a>
            @endif
        @endauth
    </div>
</article>
