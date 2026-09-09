{{--
    Panel moderacji wymaga potwierdzonego 2FA (issue #12).

    To NIE JEST ściana — moderator dostaje jasny powód i przycisk prosto
    do włączenia, zamiast 404 albo 403 bez wyjaśnienia. Panel widzi zgłoszenia,
    cudze ukryte treści i odwołania, dlatego samo hasło już nie wystarcza.
--}}
<x-layout title="Włącz weryfikację dwuetapową — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Weryfikacja dwuetapowa" />

    <h1>Ten panel wymaga weryfikacji dwuetapowej</h1>

    <p class="mb-5">
        Panel moderacji pokazuje zgłoszenia, ukryte treści i odwołania — konta z dostępem do niego
        chronimy mocniej niż zwykłe konto. Zanim wejdziesz, włącz weryfikację dwuetapową: to kod
        z aplikacji w telefonie, obok hasła.
    </p>

    <p class="mb-5">Zajmuje mniej niż dwie minuty.</p>

    <div class="form-actions">
        <a class="btn btn-primary" href="{{ route('settings.two_factor.enable') }}">Włącz weryfikację dwuetapową</a>
        <a class="btn btn-quiet" href="{{ route('home') }}">Wróć na stronę główną</a>
    </div>
</x-layout>
