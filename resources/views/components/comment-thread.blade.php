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
@props(['comments', 'action'])
<section class="stack" aria-labelledby="komentarze">
    <h2 id="komentarze">Komentarze @if($comments->count()) ({{ $comments->count() }}) @endif</h2>

    @forelse($comments as $comment)
        <article class="card">
            <div style="display:flex; gap:var(--spacing-3); align-items:center; margin-bottom:var(--spacing-2);">
                <x-avatar :user="$comment->author" :size="40" />
                <div>
                    <a class="author-name" href="{{ route('profile.show', $comment->author->profile->username) }}">{{ $comment->author->displayName() }}</a>
                    <p class="meta" style="margin:0;">
                        <time datetime="{{ $comment->created_at->toIso8601String() }}">{{ $comment->created_at->translatedFormat('j F Y, H:i') }}</time>
                    </p>
                </div>
            </div>

            @php($commentIsRemoved = $comment->body === 'Komentarz usunięty.')

            @if($commentIsRemoved)
                <p class="meta" style="font-style:italic;">{{ $comment->body }}</p>
            @else
                <p style="white-space:pre-line; overflow-wrap:anywhere;">{{ $comment->body }}</p>
            @endif

            @foreach($comment->replies as $reply)
                <div style="margin-left:var(--spacing-6); padding-left:var(--spacing-4); border-left:3px solid var(--color-border);">
                    <div style="display:flex; gap:var(--spacing-2); align-items:center;">
                        <x-avatar :user="$reply->author" :size="32" />
                        <a class="author-name" href="{{ route('profile.show', $reply->author->profile->username) }}">{{ $reply->author->displayName() }}</a>
                        <span class="meta">{{ $reply->created_at->translatedFormat('j F Y, H:i') }}</span>
                    </div>

                    @php($replyIsRemoved = $reply->body === 'Komentarz usunięty.')

                    @if($replyIsRemoved)
                        <p class="meta" style="font-style:italic;">{{ $reply->body }}</p>
                    @else
                        <p style="white-space:pre-line; overflow-wrap:anywhere;">{{ $reply->body }}</p>

                        @auth
                            @php($replyRemainingMinutes = 15 - (int) $reply->created_at->diffInMinutes(now()))
                            @php($replyContentOwnerRemovingOthers = auth()->id() !== $reply->author_id && auth()->id() === $reply->notifiableUserId())

                            @can('update', $reply)
                                @if($replyRemainingMinutes > 0)
                                    <details style="margin-top:var(--spacing-2);">
                                        <summary class="btn btn-quiet" style="display:inline-flex;">Popraw</summary>
                                        <p class="meta">Możesz poprawić jeszcze przez {{ $replyRemainingMinutes }} {{ \Illuminate\Support\Str::plural('minutę', $replyRemainingMinutes) }}.</p>
                                        <form method="POST" action="{{ route('comments.update', $reply) }}" style="margin-top:var(--spacing-2);">
                                            @csrf
                                            @method('PUT')
                                            <x-field name="body" label="Popraw swoją odpowiedź" type="textarea" :rows="3" :value="$reply->body" required />
                                            <button class="btn btn-primary" type="submit">Zapisz poprawkę</button>
                                        </form>
                                    </details>
                                @endif
                            @endcan

                            @can('delete', $reply)
                                <div class="danger-zone" style="margin-top:var(--spacing-2); padding-top:var(--spacing-3);">
                                    @if($replyContentOwnerRemovingOthers)
                                        <details>
                                            <summary class="btn btn-quiet" style="display:inline-flex;">Usuń</summary>
                                            <form method="POST" action="{{ route('comments.destroy', $reply) }}" style="margin-top:var(--spacing-2);">
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
                <details style="margin-top:var(--spacing-3);">
                    <summary class="btn btn-quiet" style="display:inline-flex;">Odpowiedz</summary>
                    <form method="POST" action="{{ $action }}" style="margin-top:var(--spacing-3);">
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
                            <details style="margin-top:var(--spacing-2);">
                                <summary class="btn btn-quiet" style="display:inline-flex;">Popraw</summary>
                                <p class="meta">Możesz poprawić jeszcze przez {{ $commentRemainingMinutes }} {{ \Illuminate\Support\Str::plural('minutę', $commentRemainingMinutes) }}.</p>
                                <form method="POST" action="{{ route('comments.update', $comment) }}" style="margin-top:var(--spacing-2);">
                                    @csrf
                                    @method('PUT')
                                    <x-field name="body" label="Popraw swój komentarz" type="textarea" :rows="4" :value="$comment->body" required />
                                    <button class="btn btn-primary" type="submit">Zapisz poprawkę</button>
                                </form>
                            </details>
                        @endif
                    @endcan

                    @can('delete', $comment)
                        <div class="danger-zone" style="margin-top:var(--spacing-2); padding-top:var(--spacing-3);">
                            @if($commentContentOwnerRemovingOthers)
                                <details>
                                    <summary class="btn btn-quiet" style="display:inline-flex;">Usuń</summary>
                                    <form method="POST" action="{{ route('comments.destroy', $comment) }}" style="margin-top:var(--spacing-2);">
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
        <p class="meta">Jeszcze nikt tu nic nie napisał. Możesz być pierwsza albo pierwszy.</p>
    @endforelse

    @auth
        <form class="card" method="POST" action="{{ $action }}">
            @csrf
            <x-field name="body" label="Napisz komentarz" type="textarea" :rows="4"
                     help="Napisz normalnie, po ludzku. Pytanie do autora też jest w porządku." required />
            <button class="btn btn-primary" type="submit">Wyślij komentarz</button>
        </form>
    @else
        <p class="notice">
            Żeby dodać komentarz, <a href="{{ route('login') }}">zaloguj się</a>
            albo <a href="{{ route('register') }}">załóż konto</a>. Zajmuje to minutę.
        </p>
    @endauth
</section>
