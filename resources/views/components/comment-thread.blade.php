{{--
    Wątek komentarzy.

    Jeden poziom odpowiedzi. Formularz odpowiedzi to zwykły <details>, więc
    działa bez JavaScriptu i nie wymaga hover ani gestu.
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

            <p style="white-space:pre-line; overflow-wrap:anywhere;">{{ $comment->body }}</p>

            @foreach($comment->replies as $reply)
                <div style="margin-left:var(--spacing-6); padding-left:var(--spacing-4); border-left:3px solid var(--color-border);">
                    <div style="display:flex; gap:var(--spacing-2); align-items:center;">
                        <x-avatar :user="$reply->author" :size="32" />
                        <a class="author-name" href="{{ route('profile.show', $reply->author->profile->username) }}">{{ $reply->author->displayName() }}</a>
                        <span class="meta">{{ $reply->created_at->translatedFormat('j F Y, H:i') }}</span>
                    </div>
                    <p style="white-space:pre-line; overflow-wrap:anywhere;">{{ $reply->body }}</p>
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
                @if(auth()->id() !== $comment->author_id)
                    <a class="btn btn-quiet" href="{{ route('reports.create', ['type' => 'comment', 'id' => $comment->getKey()]) }}">Zgłoś</a>
                @endif
            @endauth
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
