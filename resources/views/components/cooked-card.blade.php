{{--
    Karta „Ugotowałem”.

    Zdjęcie cudzego wykonania jest tu najważniejszym elementem — to jest
    dowód, że przepis działa u zwykłego człowieka, a nie na sesji zdjęciowej.
--}}
@props(['event', 'showRecipe' => false])
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
            @if($event->recipe)
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
        <div class="photo-grid mb-3 rounded-md overflow-hidden">
            @foreach($event->media as $media)
                <x-photo :media="$media" />
            @endforeach
        </div>
    @endif

    @if($event->note)
        <p class="tekst-jak-napisano">{{ $event->note }}</p>
    @endif

    @if($event->changes_note)
        {{-- „Po swojemu", nie „Zrobiłam/zrobiłem po swojemu" (issue #38).
             Ta sama reguła, co osiem linijek wyżej przy „ugotowane":
             `docs/brand/COPY_STYLE.md` §2 każe zmienić konstrukcję zdania,
             a nie wybierać rodzaj ukośnikiem. Tu wystarczyło skreślić
             czasownik — podpis stoi nad cudzą notatką, więc kto ją napisał,
             wiadomo z karty wyżej. --}}
        <p><strong>Po swojemu:</strong> {{ $event->changes_note }}</p>
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

    <p class="mt-3">
        <a class="btn btn-secondary" href="{{ route('cooked.show', $event) }}">Zobacz i skomentuj</a>
    </p>
</article>
