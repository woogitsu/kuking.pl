@props(['tag', 'photo' => null, 'stats'])

<a class="tag-directory-card {{ $photo ? 'tag-directory-card--photo' : '' }}" href="{{ route('tags.show', $tag) }}">
    @if($photo)
        {{-- Karta ma ~320–380 px (`minmax(20rem, 1fr)`, marka-tagi.css), a stron
             bywa 100 kart — `srcset` z prawdziwych szerokości pozwala nie brać
             `feed` 960 px tam, gdzie wystarcza mniejszy wariant (#1326). --}}
        <img class="tag-directory-photo" src="{{ $photo->url('feed') }}"
             srcset="{{ $photo->srcset() }}" sizes="(min-width: 48rem) 24rem, 100vw"
             alt="" loading="lazy" decoding="async">
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
