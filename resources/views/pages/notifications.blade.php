<x-layout title="Powiadomienia" :noindex="true">
    <h1>Powiadomienia</h1>

    @if($notifications->total() > 0)
        <form method="POST" action="{{ route('notifications.read') }}" style="margin-bottom:var(--spacing-5);">
            @csrf
            <button class="btn btn-secondary" type="submit">Oznacz wszystkie jako przeczytane</button>
        </form>
    @endif

    @forelse($notifications as $notification)
        @php
            $actor = $notification->actor;
            $data = $notification->data ?? [];
        @endphp
        <article class="card @if($notification->isUnread()) style-unread @endif"
                 style="margin-bottom:var(--spacing-3); @if($notification->isUnread()) border-left:4px solid var(--color-brand); @endif">
            <div style="display:flex; gap:var(--spacing-3); align-items:flex-start;">
                @if($actor)
                    <x-avatar :user="$actor" :size="44" />
                @endif
                <div style="min-width:0;">
                    <p style="margin:0 0 var(--spacing-1);">
                        @switch($notification->type)
                            @case(\App\Models\Notification::TYPE_COOKED)
                                <strong>{{ $actor?->displayName() }} ugotowała/ugotował z Twojego przepisu</strong>
                                „{{ $data['recipe_title'] ?? 'przepis' }}”.
                                @if($data['has_photo'] ?? false) Jest zdjęcie. @endif
                                @break
                            @case(\App\Models\Notification::TYPE_COMMENT)
                                <strong>{{ $actor?->displayName() }} napisała/napisał komentarz.</strong>
                                @if(isset($data['excerpt'])) „{{ $data['excerpt'] }}” @endif
                                @break
                            @case(\App\Models\Notification::TYPE_REPLY)
                                <strong>{{ $actor?->displayName() }} odpowiedziała/odpowiedział.</strong>
                                @if(isset($data['excerpt'])) „{{ $data['excerpt'] }}” @endif
                                @break
                            @case(\App\Models\Notification::TYPE_FOLLOW)
                                <strong>{{ $actor?->displayName() }} zaczęła/zaczął Cię obserwować.</strong>
                                @break
                            @case(\App\Models\Notification::TYPE_SAVED)
                                <strong>{{ $actor?->displayName() }} zapisała/zapisał Twój przepis</strong>
                                „{{ $data['recipe_title'] ?? '' }}” do swojego zeszytu.
                                @break
                            @case(\App\Models\Notification::TYPE_FIRST_POST)
                                {{-- Powiadomienie dla GOSPODARZA, nie dla autora
                                     (issue #6). Pierwszy wpis to jedyna okazja,
                                     żeby ktoś poczuł, że jest tu ktoś po drugiej
                                     stronie — i mamy na to dobę. --}}
                                <strong>{{ $data['display_name'] ?? 'Ktoś' }} opublikowała pierwszy wpis.</strong>
                                Odpowiedz jak najszybciej — pierwszy wpis bez reakcji zwykle bywa ostatnim.
                                @break
                            @case(\App\Models\Notification::TYPE_WELCOME)
                                <strong>Witamy w Kuking, {{ $data['display_name'] ?? '' }}.</strong>
                                Zacznij od zdjęcia tego, co dziś ugotowałaś. Nie musi być ładne — ma być prawdziwe.
                                @break
                            @case(\App\Models\Notification::TYPE_MODERATION)
                                {{-- Nagłówek mówi, CO SIĘ STAŁO, a pod nim idzie treść
                                     napisana przez moderatora. Starsze powiadomienia
                                     (usunięcie komentarza przez autora treści) nie mają
                                     `title` — dla nich zostaje dawny nagłówek. --}}
                                <strong>{{ $data['title'] ?? 'Wiadomość od moderacji Kuking.' }}</strong>
                                {{ $data['message'] ?? '' }}
                                {{-- Prawo do odwołania (DSA art. 17) musi być NAPISANE,
                                     nie domyślne. Adres bierzemy z konfiguracji, żeby
                                     jego zmiana nie zostawiła starych powiadomień
                                     z martwym kontaktem. --}}
                                @if($data['appeal'] ?? false)
                                    <br>
                                    @if($data['action_id'] ?? null)
                                        {{-- Odwołanie składa się w serwisie, nie mailem
                                             (issue #10). Przycisk jest niżej — tu zostaje
                                             samo zdanie, żeby człowiek wiedział, czego
                                             dotyczy. --}}
                                        <span>Jeśli uważasz, że to pomyłka, możesz się odwołać.
                                            Sprawdzimy decyzję jeszcze raz.</span>
                                    @else
                                        {{-- Powiadomienia sprzed issue #10 nie wiedzą,
                                             której decyzji dotyczą — dla nich zostaje
                                             adres e-mail. Adres bierzemy z konfiguracji,
                                             żeby jego zmiana nie zostawiła starych
                                             powiadomień z martwym kontaktem. --}}
                                        <span>Jeśli uważasz, że to pomyłka, możesz się odwołać:
                                            napisz na {{ config('kuking.community.contact_email') }}.
                                            Sprawdzimy decyzję jeszcze raz.</span>
                                    @endif
                                @endif
                                @break
                            @default
                                {{ $notification->type }}
                        @endswitch
                    </p>
                    <p class="meta" style="margin:0;">
                        <time datetime="{{ $notification->created_at->toIso8601String() }}">{{ \App\Support\Czas::lokalnie($notification->created_at)->diffForHumans() }}</time>
                    </p>

                    @php
                        $link = match ($notification->type) {
                            // Prowadzi do pełnoekranowego ekranu „Komuś wyszło" (issue #17),
                            // nie od razu do zwykłego wpisu — to jest najcenniejszy moment
                            // w produkcie i zasługuje na własną stronę, nie jeden wiersz
                            // na liście. `celebrate()` sam się cofa do `cooked.show`,
                            // kiedy ekran już był raz pokazany.
                            \App\Models\Notification::TYPE_COOKED => isset($data['cooked_event_id']) ? route('cooked.celebrate', $data['cooked_event_id']) : null,
                            \App\Models\Notification::TYPE_SAVED => isset($data['recipe_slug']) ? route('recipes.show', $data['recipe_slug']) : null,
                            \App\Models\Notification::TYPE_FOLLOW => isset($data['username']) ? route('profile.show', $data['username']) : null,
                            \App\Models\Notification::TYPE_FIRST_POST => route('admin.unanswered'),
                            \App\Models\Notification::TYPE_WELCOME => route('posts.create'),
                            default => $data['url'] ?? null,
                        };
                    @endphp
                    @if($link)
                        <p style="margin:var(--spacing-3) 0 0;">
                            <a class="btn btn-secondary" href="{{ $link }}">Zobacz</a>
                        </p>
                    @endif

                    {{--
                        Droga do odwołania (issue #10, DSA art. 17 i 20).

                        Przycisk prowadzi do sprawy, nie do samego formularza:
                        ta sama strona pokazuje formularz, złożone już odwołanie
                        albo minięty termin. Dzięki temu nigdy nie prowadzi do
                        ściany 403 — a napis mówi, co się za nim kryje, bo ikona
                        nigdy nie jest jedynym opisem akcji (UX 50+).
                    --}}
                    @if(($data['appeal'] ?? false) && ($data['action_id'] ?? null))
                        <p style="margin:var(--spacing-3) 0 0;">
                            <a class="btn btn-secondary" href="{{ route('appeals.show', $data['action_id']) }}">
                                Odwołanie od tej decyzji
                            </a>
                        </p>
                    @endif
                </div>
            </div>
        </article>
    @empty
        <x-empty-state title="Nie ma jeszcze żadnych powiadomień">
            Tu pojawi się informacja, kiedy ktoś ugotuje z Twojego przepisu albo napisze komentarz.
        </x-empty-state>
    @endforelse

    <div style="margin-top:var(--spacing-6);">{{ $notifications->links() }}</div>
</x-layout>
