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
--}}
{{--
    `ile` to LICZBA WSZYSTKICH wątków, nie tylko tych na stronie. Bez tego
    parametru nagłówek przy paginacji kłamałby: pokazywałby „Komentarze (12)"
    pod treścią, która ma ich sto. Domyślnie `null`, więc ekrany bez
    paginacji nie muszą nic przekazywać i liczą jak dotąd.
--}}
@props(['comments', 'action', 'ile' => null])
@php($wszystkich = $ile ?? $comments->count())
<section class="stack" aria-labelledby="komentarze">
    <h2 id="komentarze">Komentarze @if($wszystkich) ({{ $wszystkich }}) @endif</h2>

    @forelse($comments as $comment)
        <article class="card">
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

            @php($commentIsRemoved = $comment->body === 'Komentarz usunięty.')

            @if($commentIsRemoved)
                <p class="meta italic">{{ $comment->body }}</p>
            @else
                <p class="tekst-jak-napisano">{{ $comment->body }}</p>
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

                    @php($replyIsRemoved = $reply->body === 'Komentarz usunięty.')

                    @if($replyIsRemoved)
                        <p class="meta italic">{{ $reply->body }}</p>
                    @else
                        <p class="tekst-jak-napisano">{{ $reply->body }}</p>

                        @auth
                            @php($replyRemainingMinutes = 15 - (int) $reply->created_at->diffInMinutes(now()))
                            @php($replyContentOwnerRemovingOthers = auth()->id() !== $reply->author_id && auth()->id() === $reply->notifiableUserId())

                            @can('update', $reply)
                                @if($replyRemainingMinutes > 0)
                                    <details class="mt-2">
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
                                            <x-field name="body" label="Popraw swoją odpowiedź" type="textarea" :rows="3" :value="$reply->body" required />
                                            <button class="btn btn-primary" type="submit">Zapisz poprawkę</button>
                                        </form>
                                    </details>
                                @endif
                            @endcan

                            @can('delete', $reply)
                                <div class="danger-zone mt-2 pt-3">
                                    @if($replyContentOwnerRemovingOthers)
                                        <details>
                                            <summary class="btn btn-quiet inline-flex">Usuń</summary>
                                            <form class="mt-2" method="POST" action="{{ route('comments.destroy', $reply) }}">
                                                @csrf
                                                @method('DELETE')
                                                <x-field name="reason" label="Dlaczego usuwasz tę odpowiedź?" type="textarea" :rows="2"
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

                            @if(auth()->id() !== $reply->author_id)
                                <a class="btn btn-quiet" href="{{ route('reports.create', ['type' => 'comment', 'id' => $reply->getKey()]) }}">Zgłoś</a>
                            @endif
                        @endauth
                    @endif
                </div>
            @endforeach

            @auth
                <details class="mt-3">
                    <summary class="btn btn-quiet inline-flex">Odpowiedz</summary>
                    <form class="mt-3" method="POST" action="{{ $action }}">
                        @csrf
                        <input type="hidden" name="parent_id" value="{{ $comment->getKey() }}">
                        <x-field name="body" label="Twoja odpowiedź" type="textarea" :rows="3" required />
                        <button class="btn btn-primary" type="submit">Wyślij odpowiedź</button>
                    </form>
                </details>
            @endauth

            @unless($commentIsRemoved)
                @auth
                    @php($commentRemainingMinutes = 15 - (int) $comment->created_at->diffInMinutes(now()))
                    @php($commentContentOwnerRemovingOthers = auth()->id() !== $comment->author_id && auth()->id() === $comment->notifiableUserId())

                    @can('update', $comment)
                        @if($commentRemainingMinutes > 0)
                            <details class="mt-2">
                                <summary class="btn btn-quiet inline-flex">Popraw</summary>
                                {{-- Ten sam błąd co przy odpowiedzi wyżej: `Str::plural` to inflektor
                                     angielski, więc pisał „3 minutęs". --}}
                                <p class="meta">Możesz poprawić jeszcze przez {{ $commentRemainingMinutes }} {{ \App\Support\Odmiana::rzeczownik($commentRemainingMinutes, 'minutę', 'minuty', 'minut') }}.</p>
                                <form class="mt-2" method="POST" action="{{ route('comments.update', $comment) }}">
                                    @csrf
                                    @method('PUT')
                                    <x-field name="body" label="Popraw swój komentarz" type="textarea" :rows="4" :value="$comment->body" required />
                                    <button class="btn btn-primary" type="submit">Zapisz poprawkę</button>
                                </form>
                            </details>
                        @endif
                    @endcan

                    @can('delete', $comment)
                        <div class="danger-zone mt-2 pt-3">
                            @if($commentContentOwnerRemovingOthers)
                                <details>
                                    <summary class="btn btn-quiet inline-flex">Usuń</summary>
                                    <form class="mt-2" method="POST" action="{{ route('comments.destroy', $comment) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-field name="reason" label="Dlaczego usuwasz ten komentarz?" type="textarea" :rows="2"
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

                    @if(auth()->id() !== $comment->author_id)
                        <a class="btn btn-quiet" href="{{ route('reports.create', ['type' => 'comment', 'id' => $comment->getKey()]) }}">Zgłoś</a>
                    @endif
                @endauth
            @endunless
        </article>
    @empty
        <p class="meta">Jeszcze nikt tu nic nie napisał. Napisz pierwszy komentarz.</p>
    @endforelse

    @auth
        <form class="panel-formularza" method="POST" action="{{ $action }}">
            @csrf
            {{-- `bez-oznaczenia`: to jedyne pole w tym formularzu, więc dopisek
                 „(wymagane)" nie miałby czego odróżniać — pełne uzasadnienie
                 przy tym parametrze w `components/field.blade.php`. --}}
            <x-field name="body" label="Napisz komentarz" type="textarea" :rows="4"
                     help="Napisz normalnie, po ludzku. Pytanie do autora też jest w porządku."
                     required bez-oznaczenia />
            <button class="btn btn-primary" type="submit">Wyślij komentarz</button>
        </form>
    @else
        {{-- BEZ „Zajmuje to minutę": obietnica z miarą, której nie mierzymy,
             a przy tym niejasna — stała po dwóch różnych drogach naraz
             (logowanie istniejącym kontem i zakładanie nowego), więc nie było
             wiadomo, o której mówi. Zostaje samo to, co jest do zrobienia. --}}
        <p class="notice">
            Żeby dodać komentarz, <a href="{{ route('login') }}">zaloguj się</a>
            albo <a href="{{ route('register') }}">załóż konto</a>.
        </p>
    @endauth

    @if($comments instanceof \Illuminate\Contracts\Pagination\Paginator)
        <x-show-more :paginator="$comments" czego="komentarzy" />
    @endif
</section>
