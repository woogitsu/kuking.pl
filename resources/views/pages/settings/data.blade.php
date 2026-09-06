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
                        @elseif($export->status === 'failed')
                            {{-- Nigdy surowa kolumna: failureReasonLabel() zamienia kod
                                 (App\Models\DataExport::REASONS) na tekst po polsku,
                                 nawet gdy kod jest nieznany albo pusty (audyt W7-07). --}}
                            <br><span class="field-help">{{ $export->failureReasonLabel() }}</span>
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
            Przez <strong>{{ $graceDays }} dni</strong> możesz jeszcze zmienić zdanie.
        </p>

        {{--
            CO DOKŁADNIE ZNIKA, A CO ZOSTAJE (decyzja D-018, audyt W4-01).

            Stało tu „dane zostaną usunięte na stałe", a niżej trzeba było
            potwierdzić „moje wpisy, przepisy i zdjęcia zostaną usunięte na
            stałe". Kod tego nie robił: kasował zdjęcie profilowe, a resztę
            zostawiał przy zanonimizowanym koncie. Obietnica złożona
            konkretnym zdaniem i niedotrzymana.

            Zdjęcia kasujemy teraz naprawdę — wszystkie. Tekst zostaje
            zanonimizowany i trzeba to powiedzieć WPROST, bo człowiek ma
            prawo wiedzieć, co po nim zostanie w cudzych zeszytach.
        --}}
        <div class="card mt-4">
            <h3 class="mt-0">Co zniknie, a co zostanie</h3>

            <p><strong>Znikną na stałe:</strong></p>
            <ul>
                <li>Twój adres e-mail, hasło i nazwa użytkownika.</li>
                <li>Twoje zdjęcie profilowe, opis i wszystko, co Cię nazywa.</li>
                <li><strong>Wszystkie Twoje zdjęcia</strong> — te we wpisach, w przepisach
                    i w wykonaniach. Także oryginały, razem z zapisaną w nich datą,
                    modelem telefonu i miejscem, w którym powstały.</li>
            </ul>

            <p><strong>Zostanie, ale bez Twojego nazwiska:</strong></p>
            <ul>
                <li>Tekst przepisów, wpisów i komentarzy — podpisany
                    „Użytkownik usunięty".</li>
            </ul>

            <p>
                Zostaje, bo to jest już także cudza historia: ktoś odpowiedział
                Ci w komentarzu, ktoś ugotował z Twojego przepisu i ma go
                w swoim zeszycie. Skasowanie tego zabrałoby coś ludziom, którzy
                o nic nie prosili. Jeśli chcesz usunąć konkretny przepis albo
                wpis w całości — usuń go sam, zanim skasujesz konto.
            </p>
        </div>
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
                    <span class="choice-label">Rozumiem, że po {{ $graceDays }} dniach moje zdjęcia i dane zostaną usunięte na stałe, a teksty zostaną bez mojego nazwiska</span>
                </label>

                <button class="btn btn-danger mt-5" type="submit">Usuń moje konto</button>
            </form>
        </details>
    </div>

    <x-ustawienia-nawigacja aktywne="data" />
</x-layout>
