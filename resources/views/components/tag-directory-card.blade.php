@props(['tag', 'photo' => null, 'stats'])

<a data-klucz="tag-{{ $tag->getKey() }}" class="tag-directory-card {{ $photo ? 'tag-directory-card--photo' : '' }}" href="{{ route('tags.show', $tag) }}">
    @if($photo)
        <img class="tag-directory-photo" src="{{ $photo->url('feed') }}" alt="" loading="lazy" decoding="async">
    @else
        <span class="tag-directory-mark" aria-hidden="true"><x-kuking-mark /></span>
    @endif
    <span class="tag-directory-copy">
        <strong>{{ $tag->name }}</strong>
        <span>({{ $tag->posts_count }} {{ \App\Support\Odmiana::rzeczownik($tag->posts_count, 'wpis', 'wpisy', 'wpisów') }})</span>
        <x-tag-public-stats :stats="$stats" :invitation="false" />
        @if($photo)
            <span class="tag-directory-credit">Zdjęcie: {{ $photo->posts->first()->author->displayName() }}</span>
        @endif
        <span class="tag-directory-action">Zobacz wpisy <span aria-hidden="true">→</span></span>
    </span>
</a>
