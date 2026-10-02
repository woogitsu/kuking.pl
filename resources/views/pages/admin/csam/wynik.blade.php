{{--
    Wynik „CSAM — natychmiast ukryj i zabezpiecz” + instrukcja zgłoszenia
    (D-333). Mówi wprost, co zrobiono i czego NIE zrobiono (blokada konta
    wyższej roli, zdjęcie, które już było kasowane). Treści ani zdjęcia
    nie pokazuje.
--}}
<x-layout title="CSAM — zabezpieczone — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="CSAM — zabezpieczone" />

    <h1>Zabezpieczone jako dowód</h1>

    <section class="panel-formularza" aria-labelledby="csam-zrobione">
        <h2 id="csam-zrobione" class="mt-0 text-title-sm">Co zrobiono</h2>
        <ul class="panel-liczby">
            <li>Rodzaj: <strong>{{ $nazwa }}</strong> — ukryty w serwisie i zabezpieczony przed usunięciem
                ({{ \App\Support\Czas::data($wpis->secured_at, 'j F Y, H:i') }}).</li>
            @if($wpis->target_type !== 'media')
                <li>Zdjęć zabezpieczonych razem z treścią: <strong>{{ $zdjec }}</strong>.</li>
            @endif
            @if($pominiete > 0)
                <li><strong>{{ $pominiete }}</strong> {{ $pominiete === 1 ? 'zdjęcie było' : 'zdjęć było' }} już w trakcie kasowania i nie dało się
                    {{ $pominiete === 1 ? 'go' : 'ich' }} zabezpieczyć. Przekaż to właścicielowi serwisu.</li>
            @endif
            @if($autor === null)
                <li>Nie znamy autora tej treści, więc nie było czyjego konta blokować.</li>
            @elseif($kontoZablokowane)
                <li>Konto autora jest <strong>zablokowane na stałe</strong>. Jego wymazanie jest wstrzymane do czasu decyzji o dowodach.</li>
            @endif
        </ul>

        @if($autor !== null && ! $kontoZablokowane)
            <p class="notice" role="alert">
                <strong>Konto autora NIE jest zablokowane.</strong>
                {{ $powodBrakuBlokady ?? 'Twoja rola nie pozwala zablokować tego konta z panelu. Przekaż sprawę administratorowi — procedura każe zablokować konto bez zwłoki.' }}
            </p>
        @endif
    </section>

    @include('pages.admin.csam._instrukcja')

    <p class="mt-4"><a class="btn btn-quiet" href="{{ route('admin.reports') }}">Wróć do zgłoszeń</a></p>
</x-layout>
