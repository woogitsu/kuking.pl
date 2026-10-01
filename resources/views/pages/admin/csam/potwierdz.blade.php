{{--
    „CSAM — natychmiast ukryj i zabezpiecz” — ekran potwierdzenia (D-333).

    CELOWO NIE POKAZUJE TREŚCI ANI ZDJĘCIA: bez cytatu, bez podglądu,
    bez miniatury (playbook §7.1 pkt 1 — „nie ściągaj pliku”). Moderator
    wie, o którą treść chodzi, z kolejki albo ze strony treści; tu ma
    tylko przeczytać, co się stanie, i jawnie potwierdzić.

    Bez JavaScriptu. Potwierdzenie to zaznaczone pole + przycisk odsunięty
    kreską `.danger-zone` (AGENTS.md §5: akcja destrukcyjna wymaga
    potwierdzenia i jest odsunięta od zwykłych). Wzorzec ekranu:
    `z-urzedu.blade.php`.
--}}
<x-layout title="CSAM — ukryj i zabezpiecz — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="CSAM — ukryj i zabezpiecz" />

    <h1>CSAM — natychmiast ukryj i zabezpiecz</h1>

    <p class="panel-liczby">
        Rodzaj: <strong>{{ $nazwa }}</strong>@if($autor) · autor: <strong>{{ $autor }}</strong>@endif.
        Ten ekran nie pokazuje treści ani zdjęcia — i tak ma zostać.
    </p>

    <x-error-summary />

    <section class="panel-formularza" aria-labelledby="csam-co-sie-stanie">
        <h2 id="csam-co-sie-stanie" class="mt-0 text-title-sm">Co się stanie, gdy to potwierdzisz</h2>
        <ul class="panel-liczby">
            <li>Treść <strong>zniknie z serwisu od razu</strong> (miękkie usunięcie — wiersz, ID treści, ID konta i czas zostają do zgłoszenia).</li>
            <li>Zdjęcia tej treści zostaną <strong>zabezpieczone</strong>: pliki zostają w magazynie, a aplikacja nie pokaże ich już nikomu — <strong>także Tobie</strong>.</li>
            <li>Treść i zdjęcia <strong>wypadają spod zwykłej retencji</strong> i nie skasuje ich ani autor, ani wymazanie konta.
                Wymazanie konta autora zostanie wstrzymane, a zabezpieczenia nie da się zdjąć z panelu.</li>
            <li>Konto autora zostanie <strong>zablokowane na stałe</strong>, jeśli Twoja rola na to pozwala. Autor dostanie jedno neutralne powiadomienie.</li>
            <li>Zapiszemy to w dzienniku audytu@if($zgloszenie) i zamkniemy to zgłoszenie@endif.</li>
            <li><strong>Nic nie zostanie wysłane na zewnątrz.</strong> Instrukcję zgłoszenia do organów dostaniesz na następnym ekranie.</li>
        </ul>
        <p class="panel-liczby">
            <strong>Nie otwieraj, nie kopiuj i nie pobieraj materiału</strong> — także po to, żeby „sprawdzić jeszcze raz”.
        </p>
    </section>

    @php($bladPotwierdzenia = $errors->first('potwierdzam'))
    <form class="panel-formularza" method="POST" action="{{ route('admin.csam.store', ['typ' => $typ, 'id' => $id]) }}" novalidate>
        @csrf
        @if($zgloszenie)
            <input type="hidden" name="zgloszenie" value="{{ $zgloszenie->getKey() }}">
        @endif

        <x-field name="note" label="Notatka wewnętrzna" type="textarea" :rows="2"
                 help="Nieobowiązkowa. Widzi ją tylko moderacja. Bez opisu samego materiału." />

        <div class="field @if($bladPotwierdzenia) has-error @endif">
            <label class="choice" for="f-potwierdzam">
                <input type="checkbox" id="f-potwierdzam" name="potwierdzam" value="1"
                       @checked(old('potwierdzam'))
                       @if($bladPotwierdzenia) aria-invalid="true" aria-describedby="f-potwierdzam-error" @endif>
                <span class="choice-label">Rozumiem: treść zniknie z serwisu od razu, a konto autora zostanie zablokowane na stałe.</span>
            </label>
            @if($bladPotwierdzenia)
                <span class="field-error" id="f-potwierdzam-error">{{ $bladPotwierdzenia }}</span>
            @endif
        </div>

        <div class="danger-zone">
            <button class="btn btn-danger" type="submit">CSAM — ukryj i zabezpiecz teraz</button>
        </div>
    </form>

    <p class="mt-4">
        <a class="btn btn-quiet" href="{{ $powrot ?? route('admin.reports') }}">Wróć bez zmian</a>
    </p>
</x-layout>
