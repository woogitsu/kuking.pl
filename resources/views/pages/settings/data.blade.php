<x-layout title="Twoje dane" :noindex="true">
    <h1>Twoje dane</h1>
    <p style="margin-bottom:var(--spacing-5);">
        Wszystko, co tu wrzuciłaś, należy do Ciebie. W każdej chwili możesz to pobrać na swój komputer.
    </p>

    <section class="card">
        <h2 style="margin-top:0;">Pobierz swoje dane</h2>
        <p>
            Przygotujemy paczkę ze wszystkimi Twoimi wpisami, przepisami, zdjęciami i komentarzami.
            Dostaniesz plik ZIP, który otworzysz na komputerze — także wtedy, gdyby Kuking kiedyś przestał istnieć.
        </p>

        <form method="POST" action="{{ route('settings.data.export') }}">
            @csrf
            <button class="btn btn-primary" type="submit">Przygotuj paczkę z moimi danymi</button>
        </form>

        @if($exports->isNotEmpty())
            <h3 style="margin-top:var(--spacing-6);">Twoje paczki</h3>
            <ul>
                @foreach($exports as $export)
                    <li>
                        {{ $export->created_at->translatedFormat('j F Y, H:i') }} —
                        @switch($export->status)
                            @case('ready') gotowa @break
                            @case('queued') w kolejce @break
                            @case('processing') przygotowujemy @break
                            @case('failed') nie udało się przygotować @break
                            @default wygasła
                        @endswitch
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <div class="danger-zone">
        <h2>Usunięcie konta</h2>
        <p>
            Konto zostanie oznaczone do usunięcia i zniknie ze strony od razu.
            Przez <strong>{{ $graceDays }} dni</strong> możesz jeszcze zmienić zdanie — potem dane zostaną usunięte na stałe.
        </p>
        <p><strong>Zanim to zrobisz, warto najpierw pobrać swoje dane.</strong></p>

        <x-error-summary />

        <details>
            <summary class="btn btn-secondary" style="display:inline-flex;">Chcę usunąć swoje konto</summary>
            <form method="POST" action="{{ route('settings.data.delete') }}" style="margin-top:var(--spacing-4);">
                @csrf
                <x-field name="password" label="Wpisz swoje hasło" type="password" required autocomplete="current-password"
                         help="Pytamy o hasło, żeby mieć pewność, że to naprawdę Ty." />

                <label class="choice" for="f-confirm" style="margin-top:var(--spacing-4);">
                    <input id="f-confirm" type="checkbox" name="confirm" value="1">
                    <span class="choice-label">Rozumiem, że po {{ $graceDays }} dniach moje wpisy, przepisy i zdjęcia zostaną usunięte na stałe</span>
                </label>

                <button class="btn btn-danger" type="submit" style="margin-top:var(--spacing-5);">Usuń moje konto</button>
            </form>
        </details>
    </div>
</x-layout>
