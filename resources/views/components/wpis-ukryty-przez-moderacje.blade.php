{{--
    Znacznik wpisu ukrytego przez moderację (#1018).

    Wpis w tym stanie widzą tylko dwie osoby: autor (żeby przeczytać decyzję
    i się odwołać) i moderator z 2FA prowadzący sprawę (`PostPolicy::view`).
    Obie muszą od pierwszego spojrzenia wiedzieć, że nikt inny go nie widzi —
    moderator szczególnie: ta sama strona co zwykły wpis bez tego napisu
    wyglądałaby na treść już przywróconą.
--}}
@props(['post'])
@if($post->status === \App\Models\Post::STATUS_HIDDEN)
    <div class="notice" role="status">
        @if(auth()->id() === $post->author_id)
            <strong>Ten wpis jest ukryty przez moderację.</strong>
            Nie widzą go inne osoby. Jeśli uważasz, że to pomyłka, odwołaj się
            przyciskiem przy powiadomieniu o tej decyzji.
            <p class="mb-0">
                <a class="btn btn-secondary" href="{{ route('notifications.index') }}">Otwórz powiadomienia</a>
            </p>
        @else
            <strong>Ukryte przez moderację.</strong>
            Widzisz ten wpis, bo obsługujesz moderację. Inne osoby go nie widzą,
            a komentowanie jest zamknięte. Tak wróci do publikacji, jeśli go przywrócisz.
            <p class="mb-0">
                <a class="btn btn-secondary" href="{{ route('admin.reports') }}">Wróć do zgłoszeń</a>
            </p>
        @endif
    </div>
@endif
