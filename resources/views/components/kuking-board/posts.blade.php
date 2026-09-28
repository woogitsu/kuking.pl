        @if($posts->isNotEmpty())
            <div class="kuking-board-kolumna">
                <h3 class="kuking-board-subtitle">{{ $posts->contains('kind', \App\Models\Post::KIND_QUESTION) ? 'Dania i pytania' : 'Dania' }}</h3>
                <ul class="kuking-board-posts">
                    @foreach($posts as $post)
                        <li class="kuking-board-post">
                            {{-- WPIS WSKAZUJĄCY PRZEPIS NIE MA WŁASNYCH ZDJĘĆ ANI
                                 TREŚCI (issue #368) — kafelek bierze jedno i drugie
                                 z relacji `$post->recipe`, zamiast trzymać kopię,
                                 która rozjechałaby się przy pierwszej edycji
                                 przepisu. Bez tego w tablicy dnia stałoby samo imię
                                 autora i pusty prostokąt po zdjęciu. --}}
                            @php
                                $glowne = $post->media->first(fn ($media) => $media->isReady())
                                    ?? $post->recipe?->heroMedia;
                                $glowne = ($glowne && $glowne->isReady()) ? $glowne : null;
                                $opis = $post->kind === \App\Models\Post::KIND_QUESTION
                                    ? $post->title
                                    : ($post->body ?: $post->recipe?->title);
                                $etykieta = $post->kind === \App\Models\Post::KIND_QUESTION ? $opis : \Illuminate\Support\Str::limit($opis ?? '', 90);
                            @endphp

                            {{-- Jeden odnośnik prowadzi do wpisu, a pseudoelement rozciąga
                                 jego kliknięcie na cały wiersz. Fokus pozostaje na pełnej
                                 nazwie: opis i notatka mogą być wyższe niż ekran przy 200%.
                                 Nazwa dostępna zachowuje kontekst dania. --}}
                            <div class="kuking-board-post-row">
                                {{-- ZDJĘCIA NIE MA — NIE MA TEŻ PUSTEGO MIEJSCA PO NIM
                                     (issue #272). Element o zerowej szerokości zabiera
                                     w kontenerze `flex` swój `gap` także wtedy, gdy nic
                                     nie zawiera. Zmierzone przy bazie 32 px: podpis
                                     takiego dania stał 24 px w prawo od krawędzi
                                     wszystkich pozostałych kart w tablicy. --}}
                                @if($glowne)
                                    <span class="kuking-board-post-photo">
                                        {{-- WARIANT WYBIERA PRZEGLĄDARKA (#1310). Na powitalnej
                                             zdjęcie ma szerokość karty (siatka `minmax(18rem, 1fr)`,
                                             najwyżej trzy dania), nie 120 px — sztywny `feed` 960 px
                                             szedł tam także do telefonu o zwykłej gęstości. W szynie
                                             i w wynikach pole ma stałe 120 px (`--tablica-zdjecie`). --}}
                                        <img src="{{ $glowne->url($naPowitalnej ? 'feed' : 'thumb') }}" alt=""
                                             srcset="{{ $glowne->srcset() }}"
                                             sizes="{{ $naPowitalnej ? '(min-width: 64rem) 33vw, 100vw' : '120px' }}"
                                             width="{{ $naPowitalnej ? ($glowne->width('feed') ?? 960) : 120 }}" height="{{ $naPowitalnej ? ($glowne->height('feed') ?? 720) : 120 }}" loading="lazy" decoding="async">
                                    </span>
                                @endif

                                {{-- `kuking-board-post-body` — to na tej klasie wisi próg
                                     dwóch kolumn (patrz `app.css`). Bez niej blok bierze
                                     rozmiar bazowy z treści i spada pod zdjęcie nawet
                                     w szynie, w której miejsce jest. --}}
                                <span class="kuking-board-post-body">
                                    <a class="author-name kuking-board-post-link" href="{{ $post->url() }}"
                                       aria-label="{{ $post->author->displayName().($opis ? ' — '.$etykieta : '') }}">{{ $post->author->displayName() }}</a>

                                    @if($opis)
                                        <span class="kuking-board-excerpt">{{ $post->kind === \App\Models\Post::KIND_QUESTION ? $opis : \Illuminate\Support\Str::limit($opis, $naPowitalnej ? 180 : 90) }}</span>
                                    @endif

                                    @if(isset($notes[$post->getKey()]))
                                        <span class="kuking-board-note">{{ $notes[$post->getKey()] }}</span>
                                    @endif
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
