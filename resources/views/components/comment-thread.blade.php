{{--
    Wątek komentarzy.

    Jeden poziom odpowiedzi. Formularze odpowiedzi, poprawki i podania powodu
    usunięcia to zwykłe <details>, więc działają bez JavaScriptu i nie
    wymagają hover ani gestu.

    Reguły KTO MOŻE CO żyją w CommentPolicy — tu tylko czytamy wynik przez
    @can, żeby nie duplikować logiki autoryzacji w widoku. Ten sam blok akcji
    (Popraw / Usuń / Zgłoś) jest powtórzony dla komentarza głównego i dla
    odpowiedzi — w tym komponencie nie ma wygodnego miejsca na współdzielony
    podkomponent bez zakładania nowego pliku.

    UKŁAD AKCJI (issue #433). Akcje zwykłe — „Odpowiedz", „Popraw", „Zgłoś" —
    stoją w JEDNYM rzędzie `.akcje-komentarza`, który się zawija. „Usuń"
    zostaje osobno, pod kreską `.danger-zone`. Podział przebiega po
    ODWRACALNOŚCI, nie po tym, kto jest autorem: dlatego „Zgłoś" wróciło do
    rzędu zwykłych akcji, choć dotąd renderowało się za kreską.
--}}
{{--
    `ile` to LICZBA WSZYSTKICH wątków, nie tylko tych na stronie. Bez tego
    parametru nagłówek przy paginacji kłamałby: pokazywałby „Komentarze (12)"
    pod treścią, która ma ich sto. Domyślnie `null`, więc ekrany bez
    paginacji nie muszą nic przekazywać i liczą jak dotąd.
--}}
@props(['comments', 'action', 'ile' => null, 'answers' => false, 'canComment' => auth()->user()?->isActive() ?? false])
@php($wszystkich = $ile ?? $comments->count())
<section class="stack" aria-labelledby="komentarze">
    <h2 id="komentarze">{{ $answers ? 'Odpowiedzi' : 'Komentarze' }} @if($wszystkich) ({{ $wszystkich }}) @endif</h2>
    @if(session('comment_edit_recovery') && is_string(old('body')))
        @php($expiredEdit = \App\Models\Comment::find(session('comment_edit_recovery')))
        @if($expiredEdit)
            @can('recoverExpiredEdit', $expiredEdit)
                <x-expired-comment-edit :body="old('body')" />
            @endcan
        @endif
    @endif
    @if($errors->has('body') || $errors->has('reason'))
        <x-error-summary />
    @endif

    @forelse($comments as $comment)
        <article class="card" id="komentarz-{{ $comment->id }}">
            <div class="flex gap-3 items-center mb-2">
                <x-avatar :user="$comment->author" :size="40" />
                <div>
                    <a class="author-name" href="{{ route('profile.show', $comment->author->profile->username) }}">{{ $comment->author->displayName() }}</a>
                    <p class="meta m-0">
                        <time datetime="{{ $comment->created_at->toIso8601String() }}">{{ \App\Support\Czas::data($comment->created_at, 'j F Y, H:i') }}</time>
                        {{-- Plakietka cicha „konto przykładowe" (D-032) po dacie — kropkę rysuje sam komponent. --}}
                        <x-konto-przykladowe :user="$comment->author" />
                    </p>
                </div>
            </div>

            {{--
                ISSUE #760: stan usunięcia wynika z ZNACZNIKA, nie z treści.

                Stało tu porównanie `$comment->body === 'Komentarz usunięty.'`.
                `CommentPolicy::update()`/`delete()` już wtedy patrzyły na
                `body_removed_at`, więc żywy komentarz o TAKIM DOSŁOWNIE
                brzmieniu (człowiek mógł go po prostu napisać) miał zgodę
                Policy na poprawienie, a ten widok i tak chował przycisk —
                autor tracił akcję, do której miał prawo.

                Placeholder to WYŁĄCZNIE prezentacja tego samego znacznika,
                którego już pilnuje Policy — nie osobne źródło prawdy.
            --}}
            @php($commentIsRemoved = $comment->body_removed_at !== null)

            @if($commentIsRemoved)
                <p class="meta italic">{{ $comment->body }}</p>
            @else
                <p class="tekst-jak-napisano">{{ \App\Support\LinkiWTekscie::render($comment->body) }}</p>
            @endif

            @foreach($comment->replies as $reply)
                <div class="watek-odpowiedzi">
                    <div class="flex gap-2 items-center">
                        <x-avatar :user="$reply->author" :size="32" />
                        <a class="author-name" href="{{ route('profile.show', $reply->author->profile->username) }}">{{ $reply->author->displayName() }}</a>
                        <span class="meta">
                            {{ \App\Support\Czas::data($reply->created_at, 'j F Y, H:i') }}
                            {{-- Plakietka cicha „konto przykładowe"
                                 (D-032) po dacie — kropkę
                                 rysuje sam komponent. --}}
                            <x-konto-przykladowe :user="$reply->author" />
                        </span>
                    </div>

                    {{-- ISSUE #760: ta sama poprawka co przy komentarzu głównym wyżej. --}}
                    @php($replyIsRemoved = $reply->body_removed_at !== null)

                    @if($replyIsRemoved)
                        <p class="meta italic">{{ $reply->body }}</p>
                    @else
                        <p class="tekst-jak-napisano">{{ \App\Support\LinkiWTekscie::render($reply->body) }}</p>

                        @auth
                            @php($replyRemainingMinutes = 15 - (int) $reply->created_at->diffInMinutes(now()))
                            @php($replyContentOwnerRemovingOthers = auth()->id() !== $reply->author_id && auth()->id() === $reply->notifiableUserId())

                            {{-- Akcje ZWYKŁE w jednym rzędzie, który się zawija —
                                 „Usuń" zostaje niżej, za kreską (`.danger-zone`).
                                 Uzasadnienie układu i zmierzone liczby stoją przy
                                 `.akcje-komentarza` w `resources/css/app.css`.

                                 Rząd bywa PUSTY: własna odpowiedź po piętnastu
                                 minutach nie ma ani „Popraw", ani „Zgłoś". Pusty
                                 nie zostawia po sobie odstępu — pilnuje tego
                                 reguła `:not(:has(> *))` w arkuszu, bo Blade
                                 zostawia w środku białe znaki i `:empty` nie
                                 trafiłoby. --}}
                            <div class="akcje-komentarza">
                                @can('update', $reply)
                                    @if($replyRemainingMinutes > 0)
                                        <details @if(\App\Support\WierszFormularza::jestAktywny('popraw-'.$reply->id) && $errors->any()) open @endif>
                                            <summary class="btn btn-quiet inline-flex">Popraw</summary>
                                            {{-- `Odmiana::rzeczownik`, nie `Str::plural` (issue #38): drugi jest
                                                 inflektorem ANGIELSKIM i przy „minutę" dokładał „s" — „Możesz
                                                 poprawić jeszcze przez 3 minutęs". Polski ma trzy formy odmiany,
                                                 nie dwie, i wyjątek na nastki (12-14), którego `Str::plural`
                                                 nie zna wcale. --}}
                                            <p class="meta">Możesz poprawić jeszcze przez {{ $replyRemainingMinutes }} {{ \App\Support\Odmiana::rzeczownik($replyRemainingMinutes, 'minutę', 'minuty', 'minut') }}.</p>
                                            <form class="mt-2" method="POST" action="{{ route('comments.update', $reply) }}">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="_wiersz" value="popraw-{{ $reply->id }}">
                                                <x-field name="body" :wiersz="'popraw-'.$reply->id" label="Popraw swoją odpowiedź" type="textarea" :rows="3" :value="$reply->body" :licznik-znakow="4000" required />
                                                <button class="btn btn-primary" type="submit">Zapisz poprawkę</button>
                                            </form>
                                        </details>
                                    @endif
                                @endcan

                                {{-- „Zgłoś" jest akcją ZWYKŁĄ, więc stoi w tym
                                     rzędzie, a nie pod kreską „Usuń". Dotąd
                                     renderowało się PO `.danger-zone`, czyli po
                                     stronie akcji nieodwracalnej — w jedynym
                                     stanie, w którym obie są naraz (autor treści
                                     ogląda cudzą odpowiedź), kreska przestawała
                                     cokolwiek oddzielać. --}}
                                @if(auth()->id() !== $reply->author_id)
                                    <a class="btn btn-quiet" href="{{ route('reports.create', ['type' => 'comment', 'id' => $reply->getKey()]) }}">Zgłoś</a>
                                @endif
                            </div>

                            @can('delete', $reply)
                                <div class="danger-zone">
                                    @if($replyContentOwnerRemovingOthers)
                                        <details @if(\App\Support\WierszFormularza::jestAktywny('usun-'.$reply->id) && $errors->any()) open @endif>
                                            <summary class="btn btn-quiet inline-flex">Usuń</summary>
                                            <form class="mt-2" method="POST" action="{{ route('comments.destroy', $reply) }}">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="_wiersz" value="usun-{{ $reply->id }}">
                                                <x-field name="reason" :wiersz="'usun-'.$reply->id" label="Dlaczego usuwasz tę odpowiedź?" type="textarea" :rows="2"
                                                         help="Osoba, która to napisała, zobaczy ten powód." required />
                                                <button class="btn btn-danger" type="submit">Usuń odpowiedź</button>
                                            </form>
                                        </details>
                                    @else
                                        <x-confirm-button
                                            :action="route('comments.destroy', $reply)"
                                            label="Usuń"
                                            question="Na pewno usunąć tę odpowiedź? Tej operacji nie da się cofnąć samodzielnie." />
                                    @endif
                                </div>
                            @endcan

                            <x-zdejmij-z-urzedu :tresc="$reply" typ="comment" />
                        @endauth
                    @endif
                </div>
            @endforeach

            @auth
                @php($commentRemainingMinutes = 15 - (int) $comment->created_at->diffInMinutes(now()))
                @php($commentContentOwnerRemovingOthers = auth()->id() !== $comment->author_id && auth()->id() === $comment->notifiableUserId())

                {{-- JEDEN RZĄD AKCJI ZWYKŁYCH, NIE TRZY WIERSZE (issue #433).

                     „Odpowiedz", „Popraw" i „Zgłoś" stoją obok siebie i zawijają
                     się, gdy zabraknie miejsca — zmierzone szerokości i to,
                     przy której szerokości okna która para przestaje się mieścić,
                     są wypisane przy `.akcje-komentarza` w `resources/css/app.css`.

                     „Usuń" ZOSTAJE POZA TYM RZĘDEM, pod kreską `.danger-zone`.
                     Akcja nieodwracalna nie ma prawa stanąć ramię w ramię ze
                     zwykłą (AGENTS.md §5) — a potwierdzenie dalej idzie przez
                     `<x-confirm-button>`, czyli przez `<details>`, bez linijki
                     JavaScriptu.

                     `@php` z minutami i z rolą właściciela treści przeniesione
                     TUTAJ, przed `@unless`: te same dwie zmienne czyta i rząd
                     akcji, i blok „Usuń" niżej, a liczenie ich w dwóch miejscach
                     byłoby dwoma miejscami do poprawienia. --}}
                <div class="akcje-komentarza">
                    @if($canComment)
                    <details @if(\App\Support\WierszFormularza::jestAktywny('odpowiedz-'.$comment->id) && $errors->any()) open @endif>
                        <summary class="btn btn-quiet inline-flex">Odpowiedz</summary>
                        <form class="mt-3" method="POST" action="{{ $action }}">
                            @csrf
                            <input type="hidden" name="parent_id" value="{{ $comment->getKey() }}">
                            <input type="hidden" name="_wiersz" value="odpowiedz-{{ $comment->id }}">
                            <x-field name="body" :wiersz="'odpowiedz-'.$comment->id" label="Twoja odpowiedź" type="textarea" :rows="3" :licznik-znakow="4000" required />
                            <button class="btn btn-primary" type="submit">Wyślij odpowiedź</button>
                        </form>
                    </details>
                    @endif

                    @unless($commentIsRemoved)
                        @can('update', $comment)
                            @if($commentRemainingMinutes > 0)
                                <details @if(\App\Support\WierszFormularza::jestAktywny('popraw-'.$comment->id) && $errors->any()) open @endif>
                                    <summary class="btn btn-quiet inline-flex">Popraw</summary>
                                    {{-- Ten sam błąd co przy odpowiedzi wyżej: `Str::plural` to inflektor
                                         angielski, więc pisał „3 minutęs". --}}
                                    <p class="meta">Możesz poprawić jeszcze przez {{ $commentRemainingMinutes }} {{ \App\Support\Odmiana::rzeczownik($commentRemainingMinutes, 'minutę', 'minuty', 'minut') }}.</p>
                                    <form class="mt-2" method="POST" action="{{ route('comments.update', $comment) }}">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="_wiersz" value="popraw-{{ $comment->id }}">
                                        <x-field name="body" :wiersz="'popraw-'.$comment->id" label="Popraw swój komentarz" type="textarea" :rows="4" :value="$comment->body" :licznik-znakow="4000" required />
                                        <button class="btn btn-primary" type="submit">Zapisz poprawkę</button>
                                    </form>
                                </details>
                            @endif
                        @endcan

                        {{-- „Zgłoś" jest akcją zwykłą — to samo, co przy
                             odpowiedzi wyżej: dotąd stało PO `.danger-zone`. --}}
                        @if(auth()->id() !== $comment->author_id)
                            <a class="btn btn-quiet" href="{{ route('reports.create', ['type' => 'comment', 'id' => $comment->getKey()]) }}">Zgłoś</a>
                        @endif
                    @endunless
                </div>
            @endauth

            @unless($commentIsRemoved)
                @auth
                    @can('delete', $comment)
                        <div class="danger-zone">
                            @if($commentContentOwnerRemovingOthers)
                                <details @if(\App\Support\WierszFormularza::jestAktywny('usun-'.$comment->id) && $errors->any()) open @endif>
                                    <summary class="btn btn-quiet inline-flex">Usuń</summary>
                                    <form class="mt-2" method="POST" action="{{ route('comments.destroy', $comment) }}">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="_wiersz" value="usun-{{ $comment->id }}">
                                        <x-field name="reason" :wiersz="'usun-'.$comment->id" label="Dlaczego usuwasz ten komentarz?" type="textarea" :rows="2"
                                                 help="Osoba, która to napisała, zobaczy ten powód." required />
                                        <button class="btn btn-danger" type="submit">Usuń komentarz</button>
                                    </form>
                                </details>
                            @else
                                <x-confirm-button
                                    :action="route('comments.destroy', $comment)"
                                    label="Usuń"
                                    question="Na pewno usunąć ten komentarz? Tej operacji nie da się cofnąć samodzielnie." />
                            @endif
                        </div>
                    @endcan

                    <x-zdejmij-z-urzedu :tresc="$comment" typ="comment" />
                @endauth
            @endunless
        </article>
    @empty
        <p class="meta">{{ $answers ? 'To pytanie czeka na odpowiedź. Podziel się swoim doświadczeniem.' : 'Jeszcze nikt tu nic nie napisał. Napisz pierwszy komentarz.' }}</p>
    @endforelse

    @auth
        @if($canComment)
        <form class="panel-formularza" method="POST" action="{{ $action }}">
            @csrf
            <input type="hidden" name="_wiersz" value="nowy-komentarz">
            {{-- `bez-oznaczenia`: to jedyne pole w tym formularzu, więc dopisek
                 „(wymagane)" nie miałby czego odróżniać — pełne uzasadnienie
                 przy tym parametrze w `components/field.blade.php`.

                 PIERWSZE ZDANIE PODPOWIEDZI WYMIENIONE, DRUGIE NIETKNIĘTE
                 (decyzja właściciela).
                 Było: „Napisz normalnie, po ludzku. Pytanie do autora też jest w porządku."
                 Dwa powody na pierwsze zdanie: etykieta pola brzmi już „Napisz
                 komentarz", więc podpowiedź zaczynała się tym samym słowem drugi
                 raz pod rząd — i mówiła, JAK pisać, czyli była metajęzykiem
                 o tonie, a nie informacją.
                 „Choćby jedno zdanie" zdejmuje presję DŁUGOŚCI. Drugie zdanie
                 zostaje celowo: zdejmuje presję TREŚCI komuś, kto nie ma nic
                 mądrego do powiedzenia o daniu, a chciałby zapytać o zamiennik
                 mąki. Razem mówią „tyle wystarczy", a nie „pisz tak".
                 Uzasadnienie: `docs/brand/GLOS_MARKI.md` §5. --}}
            <x-field name="body" :wiersz="old('_wiersz') !== null ? 'nowy-komentarz' : null" :label="$answers ? 'Napisz odpowiedź' : 'Napisz komentarz'" type="textarea" :rows="4"
                     :help="$answers ? 'Napisz, co sprawdziło się w Twojej kuchni.' : 'Choćby jedno zdanie. Pytanie do autora też jest w porządku.'"
                     :licznik-znakow="4000"
                     required bez-oznaczenia />
            <button class="btn btn-primary" type="submit">{{ $answers ? 'Wyślij odpowiedź' : 'Wyślij komentarz' }}</button>
        </form>
        @else
            <p class="notice">Możesz czytać komentarze. Wróć do rozmowy po zakończeniu zawieszenia konta.</p>
        @endif
    @else
        {{-- BEZ „Zajmuje to minutę": obietnica z miarą, której nie mierzymy,
             a przy tym niejasna — stała po dwóch różnych drogach naraz
             (logowanie istniejącym kontem i zakładanie nowego), więc nie było
             wiadomo, o której mówi. Zostaje samo to, co jest do zrobienia. --}}
        <p class="notice">
            {{ $answers ? 'Żeby odpowiedzieć,' : 'Żeby dodać komentarz,' }} <a href="{{ route('login') }}">zaloguj się</a>
            albo <a href="{{ route('register') }}">załóż konto</a>.
        </p>
    @endauth

    @if($comments instanceof \Illuminate\Contracts\Pagination\Paginator)
        <x-show-more :paginator="$comments" czego="komentarzy" />
    @endif
</section>
