@props(['photos', 'linked' => true])

@if($photos->isNotEmpty())
    <div class="tag-collage tag-collage--{{ $photos->count() }}" data-tag-collage>
        @foreach($photos as $photo)
            @php($post = $photo->posts->first())
            @if($linked)
                <a href="{{ $post->url() }}"
                   aria-label="{{ 'Zobacz wpis: '.$post->author->displayName() }}">
                    <img src="{{ $photo->url('feed') }}" alt="" loading="lazy" decoding="async">
                </a>
            @else
                <span>
                    <img src="{{ $photo->url('thumb') }}" alt="" loading="lazy" decoding="async">
                </span>
            @endif
        @endforeach
    </div>
@endif
