<x-layout title="Twoje dane" :noindex="true">
    <h1>Twoje dane</h1>
    <p class="mb-5">
        Wszystko, co tu wrzuciłaś, należy do Ciebie. W każdej chwili możesz to pobrać na swój komputer.
    </p>

    <section class="card">
        <h2 class="mt-0">Pobierz swoje dane</h2>
        <p>
            Przygotujemy paczkę ze wszystkimi Twoimi wpisami, przepisami, zdjęciami i komentarzami.
            Dostaniesz plik ZIP, który otworzysz na komputerze — także wtedy, gdyby Kuking kiedyś przestał istnieć.
        </p>

        <form method="POST" action="{{ route('settings.data.export') }}">
            @csrf
            <button class="btn btn-primary" type="submit">Przygotuj paczkę z moimi danymi</button>
        </form>

        @if($exports->isNotEmpty())
            <h3 class="mt-6">Twoje paczki</h3>
            <ul>
                @foreach($exports as $export)
                    <li class="mb-4">
                        {{ \App\Support\Czas::data($export->created_at, 'j F Y, H:i') }} —
                        @switch($export->status)
                            @case('ready') gotowa @break
                            @case('queued') w kolejce @break
                            @case('processing') przygotowujemy @break
                            @case('failed') nie udało się przygotować @break
                            @default wygasła
                        @endswitch

                        @if(isset($downloadUrls[$export->getKey()]))
                            <br>
                            <a class="btn btn-primary mt-2"
                               href="{{ $downloadUrls[$export->getKey()] }}">Pobierz paczkę</a>
                            <br>
                            <span class="field-help">Do pobrania do {{ \App\Support\Czas::data($export->expires_at, 'j F Y') }}.</span>
                        @elseif($export->status === 'failed' && $export->failure_reason)
                            <br><span class="field-help">{{ $export->failure_reason }}</span>
                        @endif
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
            <summary class="btn btn-secondary inline-flex">Chcę usunąć swoje konto</summary>
            <form class="mt-4" method="POST" action="{{ route('settings.data.delete') }}">
                @csrf
                <x-field name="password" label="Wpisz swoje hasło" type="password" required autocomplete="current-password"
                         help="Pytamy o hasło, żeby mieć pewność, że to naprawdę Ty." />

                <label class="choice mt-4" for="f-confirm">
                    <input id="f-confirm" type="checkbox" name="confirm" value="1">
                    <span class="choice-label">Rozumiem, że po {{ $graceDays }} dniach moje wpisy, przepisy i zdjęcia zostaną usunięte na stałe</span>
                </label>

                <button class="btn btn-danger mt-5" type="submit">Usuń moje konto</button>
            </form>
        </details>
    </div>

    <x-ustawienia-nawigacja aktywne="data" />
</x-layout>
