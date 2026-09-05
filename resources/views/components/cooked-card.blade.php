{{--
    Karta „Ugotowałem”.

    Zdjęcie cudzego wykonania jest tu najważniejszym elementem — to jest
    dowód, że przepis działa u zwykłego człowieka, a nie na sesji zdjęciowej.
--}}
@props(['event', 'showRecipe' => false])
<article class="card">
    <div style="display:flex; gap:var(--spacing-3); align-items:center; margin-bottom:var(--spacing-3);">
        <x-avatar :user="$event->user" :size="44" />
        <div style="min-width:0;">
            <a class="author-name" href="{{ route('profile.show', $event->user->profile->username) }}">{{ $event->user->displayName() }}</a>
            <p class="meta" style="margin:0;">
                ugotowała/ugotował
                <time datetime="{{ $event->cooked_at->toIso8601String() }}">{{ $event->cooked_at->translatedFormat('j F Y') }}</time>
            </p>
        </div>
    </div>

    @if($showRecipe)
        <p style="margin:0 0 var(--spacing-3);">
            z przepisu <a href="{{ route('recipes.show', $event->recipe->slug) }}">{{ $event->recipe->title }}</a>
        </p>
    @endif

    @if($event->media->isNotEmpty())
        <div class="photo-grid" style="margin-bottom:var(--spacing-3); border-radius:var(--radius-md); overflow:hidden;">
            @foreach($event->media as $media)
                <x-photo :media="$media" />
            @endforeach
        </div>
    @endif

    @if($event->note)
        <p style="white-space:pre-line; overflow-wrap:anywhere;">{{ $event->note }}</p>
    @endif

    @if($event->changes_note)
        <p><strong>Zrobiłam/zrobiłem po swojemu:</strong> {{ $event->changes_note }}</p>
    @endif

    <ul class="recipe-facts">
        @if($event->would_make_again !== null)
            <li><span class="badge">{{ $event->would_make_again ? 'Zrobię ponownie' : 'Raczej nie powtórzę' }}</span></li>
        @endif
        @if($event->actual_minutes)
            <li><span class="badge">Zajęło mi {{ $event->actual_minutes }} min</span></li>
        @endif
        @if($event->perceived_difficulty)
            <li><span class="badge">{{ \App\Models\Recipe::DIFFICULTY_LABELS[$event->perceived_difficulty] }}</span></li>
        @endif
    </ul>

    <p style="margin-top:var(--spacing-3);">
        <a class="btn btn-secondary" href="{{ route('cooked.show', $event) }}">Zobacz i skomentuj</a>
    </p>
</article>
