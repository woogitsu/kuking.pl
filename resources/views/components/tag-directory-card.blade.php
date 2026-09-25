@props(['tag', 'photo' => null, 'stats'])

{{-- `rel="nofollow"` przy tagu bez publicznego wpisu (issue #1007): link zostaje
     dla ludzi (D-087, prawdziwe zero), ale robot nie jest zapraszany na
     ~1400 pustych stron. Sama strona i tak ma `noindex, follow`. --}}
<a class="tag-directory-card {{ $photo ? 'tag-directory-card--photo' : '' }}" href="{{ route('tags.show', $tag) }}" @if($tag->posts_count === 0) rel="nofollow" @endif>
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
