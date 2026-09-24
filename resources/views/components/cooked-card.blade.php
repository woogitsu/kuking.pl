{{--
    Karta „Ugotowałem”.

    Zdjęcie cudzego wykonania jest tu najważniejszym elementem — to jest
    dowód, że przepis działa u zwykłego człowieka, a nie na sesji zdjęciowej.
--}}
@props(['event', 'showRecipe' => false, 'przepisZaBlokada' => false])
<article class="card">
    <div class="flex gap-3 items-center mb-3">
        <x-avatar :user="$event->user" :size="44" />
        <div class="min-w-0">
            <a class="author-name" href="{{ route('profile.show', $event->user->profile->username) }}">{{ $event->user->displayName() }}</a>
            {{-- „ugotowane", nie „ugotowała/ugotował" (issue #38).
                 Ukośnik zakłada, że trzeba wybrać rodzaj, i nie da się go
                 przeczytać na głos — `docs/brand/COPY_STYLE.md` §2 każe w takim
                 razie zmienić konstrukcję zdania. Imiesłów bierny mówi to samo
                 i nie pyta, kto gotował. --}}
            <p class="meta m-0">
                ugotowane
                <time datetime="{{ $event->cooked_at->toIso8601String() }}">{{ \App\Support\Czas::data($event->cooked_at, 'j F Y') }}</time>
            </p>
        </div>
    </div>

    @if($showRecipe)
        <p class="m-0 mb-3">
            @if($event->recipe && $przepisZaBlokada)
                {{--
                    Między osobą, która patrzy, a autorem przepisu jest blokada
                    (issue #1394). Kucharz dalej widzi swoje zdjęcie i notatkę,
                    ale tytuł i adres przepisu to treść autora — blokada
                    wycina ją w obie strony (AGENTS.md §4).
                --}}
                Ten przepis nie jest dla Ciebie dostępny. Twoje zdjęcie i notatka zostają.
            @elseif($event->recipe)
                z przepisu <a href="{{ route('recipes.show', $event->recipe->slug) }}">{{ $event->recipe->title }}</a>
            @else
                {{--
                    Przepis został usunięty (audyt A23). Świadomie NIE sięgamy
                    po withTrashed(): tytuł i adres przepisu, który autor sam
                    skasował, nie mogą wrócić na ekran bocznymi drzwiami.
                    Zdjęcie i notatka zostają, bo to jest treść kucharza.
                --}}
                Przepisu, z którego to powstało, już nie ma.
            @endif
        </p>
    @endif

    @if($event->media->isNotEmpty())
        @php
            $liczbaZdjec = $event->media->count();
        @endphp
        <div class="photo-grid mb-3 rounded-md overflow-hidden">
            @foreach($event->media as $index => $media)
                @php
                    $domyslnyAlt = $liczbaZdjec > 1
                        ? 'Zdjęcie '.($index + 1).' z '.$liczbaZdjec.' wykonania'
                        : 'Zdjęcie wykonania';
                @endphp
                <x-photo :media="$media" :alt="$media->alt_text ?: $domyslnyAlt" />
            @endforeach
        </div>
    @endif

    @if($event->note)
        <p class="tekst-jak-napisano">{{ $event->note }}</p>
    @endif

    @if($event->changes_note)
        <p><strong>Po swojemu:</strong> {{ $event->changes_note }}</p>
    @endif

    <ul class="recipe-facts">
        @if($event->would_make_again !== null)
            <li><span class="badge">{{ $event->would_make_again ? 'Zrobię ponownie' : 'Raczej nie powtórzę' }}</span></li>
        @endif
        @if($event->actual_minutes !== null)
            <li><span class="badge">Zajęło mi {{ $event->actual_minutes }} min</span></li>
        @endif
        @if($event->perceived_difficulty)
            <li><span class="badge">{{ \App\Models\Recipe::DIFFICULTY_LABELS[$event->perceived_difficulty] }}</span></li>
        @endif
    </ul>

    <p class="mt-3">
        <a class="btn btn-secondary" href="{{ route('cooked.show', $event) }}">Zobacz i skomentuj</a>
    </p>
</article>
