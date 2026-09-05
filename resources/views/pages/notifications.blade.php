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
                            @case(\App\Models\Notification::TYPE_WELCOME)
                                <strong>Witamy w Kuking, {{ $data['display_name'] ?? '' }}.</strong>
                                Zacznij od zdjęcia tego, co dziś ugotowałaś. Nie musi być ładne — ma być prawdziwe.
                                @break
                            @case(\App\Models\Notification::TYPE_MODERATION)
                                <strong>Wiadomość od moderacji Kuking.</strong>
                                {{ $data['message'] ?? '' }}
                                @break
                            @default
                                {{ $notification->type }}
                        @endswitch
                    </p>
                    <p class="meta" style="margin:0;">
                        <time datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->diffForHumans() }}</time>
                    </p>

                    @php
                        $link = match ($notification->type) {
                            \App\Models\Notification::TYPE_COOKED => isset($data['cooked_event_id']) ? route('cooked.show', $data['cooked_event_id']) : null,
                            \App\Models\Notification::TYPE_SAVED => isset($data['recipe_slug']) ? route('recipes.show', $data['recipe_slug']) : null,
                            \App\Models\Notification::TYPE_FOLLOW => isset($data['username']) ? route('profile.show', $data['username']) : null,
                            \App\Models\Notification::TYPE_WELCOME => route('posts.create'),
                            default => $data['url'] ?? null,
                        };
                    @endphp
                    @if($link)
                        <p style="margin:var(--spacing-3) 0 0;">
                            <a class="btn btn-secondary" href="{{ $link }}">Zobacz</a>
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
