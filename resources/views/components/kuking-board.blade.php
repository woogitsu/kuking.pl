{{--
    Tablica „kuKINGi na dziś".

    Kilka osób i kilka dań wartych zobaczenia dzisiaj. To NIE jest ranking —
    nigdzie nie pokazujemy liczby obserwujących ani niczego, co wygląda
    na miarę popularności.

    Stopka „Jutro będzie tu ktoś inny." jest częścią funkcji, nie ozdobą:
    mówi wprost, że to się zmienia i nie jest tabelą wyników.

    Teksty: docs/brand/COPY_STYLE.md §5
--}}
@props(['people', 'posts', 'notes' => []])

@php $pusta = $people->isEmpty() && $posts->isEmpty(); @endphp

<section class="card kuking-board" aria-labelledby="kuking-na-dzis" style="margin-bottom:var(--spacing-6);">
    <h2 id="kuking-na-dzis" style="margin-top:0;">
        <x-kuking-word forma="i" /> na dziś
    </h2>

    @if($pusta)
        <p class="meta" style="margin-bottom:0;">
            Dziś jeszcze nikogo nie wybraliśmy.
            Zajrzyj do <a href="{{ route('discover') }}">Świeżo z Kuking</a>.
        </p>
    @else
        <p class="meta">Kilka osób i kilka dań, które dziś warto zobaczyć.</p>

        @if($people->isNotEmpty())
            <h3 class="kuking-board-subtitle">Osoby</h3>
            <ul class="kuking-board-people">
                @foreach($people as $person)
                    <li class="kuking-board-person">
                        <a href="{{ route('profile.show', $person->profile->username) }}" tabindex="-1" aria-hidden="true">
                            <x-avatar :user="$person" :size="52" />
                        </a>

                        <div style="min-width:0; flex:1;">
                            <a class="author-name" href="{{ route('profile.show', $person->profile->username) }}">{{ $person->displayName() }}</a>
                            <p class="meta" style="margin:0;">
                                {{ $person->profile->speciality ?? 'Gotuje w Kuking' }}
                                @if($person->profile->region) · {{ $person->profile->region }} @endif
                            </p>

                            @if(isset($notes[$person->getKey()]))
                                <p class="kuking-board-note">{{ $notes[$person->getKey()] }}</p>
                            @endif

                            {{-- Podgląd trzech ostatnich zdjęć. To jest jedyny
                                 uczciwy argument, żeby kogoś zaobserwować. --}}
                            @php
                                $podglad = $person->posts
                                    ->flatMap(fn ($post) => $post->media)
                                    ->filter(fn ($media) => $media->isReady())
                                    ->take(3);
                            @endphp
                            @if($podglad->isNotEmpty())
                                <div class="kuking-board-preview">
                                    @foreach($podglad as $media)
                                        <img src="{{ $media->url('thumb') }}" alt=""
                                             width="72" height="72" loading="lazy" decoding="async">
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        @auth
                            <form method="POST" action="{{ route('social.follow', $person->profile->username) }}">
                                @csrf
                                <button class="btn btn-secondary" type="submit">Obserwuj</button>
                            </form>
                        @else
                            <a class="btn btn-secondary" href="{{ route('register') }}">Obserwuj</a>
                        @endauth
                    </li>
                @endforeach
            </ul>
        @endif

        @if($posts->isNotEmpty())
            <h3 class="kuking-board-subtitle">Dania</h3>
            <ul class="kuking-board-posts">
                @foreach($posts as $post)
                    <li class="kuking-board-post">
                        @php $glowne = $post->media->first(fn ($media) => $media->isReady()); @endphp

                        <a href="{{ $post->url() }}" class="kuking-board-post-photo" tabindex="-1" aria-hidden="true">
                            @if($glowne)
                                <img src="{{ $glowne->url('thumb') }}" alt=""
                                     width="96" height="96" loading="lazy" decoding="async">
                            @endif
                        </a>

                        <div style="min-width:0;">
                            <p style="margin:0 0 var(--spacing-1);">
                                <a class="author-name" href="{{ route('profile.show', $post->author->profile->username) }}">{{ $post->author->displayName() }}</a>
                            </p>

                            @if($post->body)
                                <p class="kuking-board-excerpt">{{ \Illuminate\Support\Str::limit($post->body, 90) }}</p>
                            @endif

                            @if(isset($notes[$post->getKey()]))
                                <p class="kuking-board-note">{{ $notes[$post->getKey()] }}</p>
                            @endif

                            <a class="btn btn-quiet" href="{{ $post->url() }}" style="padding-left:0;">Zobacz</a>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="meta kuking-board-footer">Jutro będzie tu ktoś inny.</p>
    @endif
</section>
