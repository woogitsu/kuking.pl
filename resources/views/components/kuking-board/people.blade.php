        @if($people->isNotEmpty())
            <div class="kuking-board-kolumna">
                <div class="marka-tablica-naglowek">
                    <h3 class="kuking-board-subtitle">{{ $wKarcie ? 'Może ich znasz?' : ($naPowitalnej ? 'Poznaj ich kuchnie' : 'Osoby') }}</h3>
                    @if($wKarcie)<a href="{{ route('search', ['sekcja' => 'ludzie']) }}" aria-label="Szukaj osób">Szukaj</a>@endif
                </div>
                <ul class="kuking-board-people">
                    @foreach($people as $person)
                        <li class="kuking-board-person">
                            <a class="kuking-board-avatar" href="{{ route('profile.show', $person->profile->username) }}" tabindex="-1" aria-hidden="true">
                                <x-avatar :user="$person" :size="$naPowitalnej ? 64 : 120" />
                            </a>

                            <div class="kuking-board-person-body">
                                <a class="author-name" href="{{ route('profile.show', $person->profile->username) }}">{{ $person->displayName() }}</a>
                                <p class="meta m-0">
                                    {{ $person->profile->speciality ?? 'Gotuje w Kuking' }}
                                    @if($person->profile->region) · {{ $person->profile->region }} @endif
                                </p>

                                @if(isset($notes[$person->getKey()]))
                                    <p class="kuking-board-note">{{ $notes[$person->getKey()] }}</p>
                                @endif
                            </div>

                            {{-- Podgląd trzech ostatnich zdjęć. To jest jedyny
                                 uczciwy argument, żeby kogoś zaobserwować.

                                 PASEK STOI JAKO BEZPOŚREDNIE DZIECKO `<li>`
                                 (issue #272), bo `flex-basis: 100%`
                                 z `.kuking-board-preview` opisuje ten pasek jako
                                 ELEMENT rzędu `.kuking-board-person`. Wewnątrz bloku
                                 tekstu (`.kuking-board-person-body`) ta reguła była
                                 martwa: rodzicem był tam zwykły blok, nie kontener
                                 `flex`, więc pasek dostawał 105 px resztki po
                                 awatarze i przycisku, a rząd trzech miniatur
                                 (232 px) zawijał po jednej na wiersz. To jest
                                 dokładnie stan ze zrzutu właściciela.

                                 KOLEJNOŚĆ: ZDJĘCIA, POTEM AKCJA. Do 11 września
                                 pasek stał ZA przyciskiem, bo przycisk siedział
                                 w tym samym wierszu co opis i wstawienie przed nim
                                 elementu na całą szerokość zepchnęłoby go pod
                                 zdjęcia. Dziś na własnym wierszu stoją OBA, więc
                                 o kolejności decyduje już tylko sens: najpierw
                                 dowód (co ta osoba ugotowała), potem decyzja
                                 (obserwuję albo nie). Miniatury są ozdobne
                                 (`alt=""`), nie da się na nie wejść klawiszem
                                 i nie wchodzą do kolejności czytania.

                                 `tests/Feature/SzynaTablicaDniaUkladTest.php` pilnuje
                                 miejsca paska w drzewie — reguła w arkuszu bez tego
                                 miejsca w HTML-u nie robi nic. --}}
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

                            @if(! $naPowitalnej || auth()->check())
                            <div class="kuking-board-akcja">
                                @auth
                                    <form method="POST" action="{{ route('social.follow', $person->profile->username) }}">
                                        @csrf
                                        {{-- #793 rozszerzone na relacje: tablica
                                             dnia stoi na stronie powitalnej
                                             i w szynie startowej, więc wisi
                                             otwarta dłużej niż większość
                                             ekranów. Nazwa w adresie mogła przez
                                             ten czas zmienić właściciela. --}}
                                        <input type="hidden" name="oczekiwany_id" value="{{ $person->getKey() }}">
                                        <button class="btn btn-secondary" type="submit">Obserwuj</button>
                                    </form>
                                @else
                                    {{-- ETYKIETA MÓWI, CO SIĘ STANIE PO KLIKNIĘCIU.

                                         Gość widział tu „Obserwuj" i trafiał na
                                         rejestrację — przycisk obiecywał akcję, której
                                         nie wykonywał. Tekst jest teraz ten sam co na
                                         profilu (`pages/profile/show.blade.php`), żeby
                                         to samo wyjście z serwisu nazywało się wszędzie
                                         tak samo. --}}
                                    <a class="btn btn-secondary" href="{{ route('register') }}">Załóż konto, żeby obserwować</a>
                                @endauth
                            </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if($naPowitalnej)
                    @guest
                        <div class="landing-tablica-zaproszenie">
                            <p>Obserwuj osoby, do których kuchni chcesz wracać.</p>
                            <a class="btn btn-primary" href="{{ route('register') }}">Załóż konto, żeby obserwować</a>
                        </div>
                    @endguest
                @endif
            </div>
        @endif
