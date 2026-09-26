<x-layout title="Powiadomienia" :noindex="true">
    <h1>Powiadomienia</h1>

    {{--
        TEN EKRAN DOTYCZY WYŁĄCZNIE KANAŁÓW POZA SERWISEM (D-303).

        Powiadomienia w serwisie — lista pod dzwonkiem — działają zawsze
        i nie mają tu przełącznika (AGENTS.md §1: „Ugotowałem" zawsze
        powiadamia autora). Mówimy to w pierwszym zdaniu, żeby nikt nie
        szukał tu wyłącznika, którego nie ma, i nie bał się, że wyłączając
        push, przestanie widzieć, kto ugotował z jego przepisu.
    --}}
    <p>
        Powiadomienia w Kuking — lista pod dzwonkiem — działają zawsze. Tu decydujesz tylko
        o tym, czy mamy dać znać także poza Kuking: na telefonie albo komputerze, nawet gdy
        strona jest zamknięta.
    </p>
    <p>
        Wysyłamy tak tylko dwie rzeczy: że ktoś ugotował z Twojego przepisu i że ktoś Ci
        odpowiedział. Nigdy reklam ani przypomnień.
    </p>

    <x-error-summary />

    {{--
        WŁĄCZENIE NA TYM URZĄDZENIU — WYMAGA JAVASCRIPTU (D-053, D-303).

        Zgodę na powiadomienia daje przeglądarka, a nie nasz formularz; bez
        skryptu nie ma czego zapytać. Dlatego przyciski stoją z `hidden`
        i odsłania je `resources/js/powiadomienia-push.js` dopiero, gdy
        przeglądarka umie Web Push — nikt nie zobaczy przycisku, który nic
        nie robi. Bez skryptu widać zdanie niżej, a wyłączenie wszędzie
        (formularz dalej) działa bez JavaScriptu.

        ZGODA TYLKO PO KLIKNIĘCIU. Przeglądarka pyta dopiero po naciśnięciu
        „Włącz…" — nigdy przy wejściu na stronę ani na ten ekran.
    --}}
    <section class="mt-8"
             data-push
             data-push-klucz="{{ $kluczPubliczny }}"
             data-push-zapisz="{{ route('settings.notifications.subscribe') }}"
             data-push-wylacz="{{ route('settings.notifications.unsubscribe') }}"
             data-push-csrf="{{ csrf_token() }}"
             data-push-znane="{{ json_encode($urzadzenia->map(fn ($u) => hash('sha256', $u->endpoint))->values()) }}">
        <h2>Na tym urządzeniu</h2>
        <p data-push-stan>
            Żeby włączyć powiadomienia na tym urządzeniu, potrzebna jest przeglądarka z włączonym JavaScriptem,
            która obsługuje powiadomienia — na przykład aktualny Chrome, Edge, Firefox albo Safari.
        </p>
        <div class="form-actions">
            <button class="btn btn-primary" type="button" data-push-przycisk-wlacz hidden>Włącz powiadomienia na tym urządzeniu</button>
            <button class="btn btn-secondary" type="button" data-push-przycisk-wylacz hidden>Wyłącz powiadomienia na tym urządzeniu</button>
        </div>
        <p class="mt-2" role="status" aria-live="polite" data-push-komunikat></p>
    </section>

    <section class="mt-8">
        <h2>Twoje urządzenia</h2>
        @if($urzadzenia->isEmpty())
            <p>Powiadomienia poza Kuking są wyłączone na wszystkich urządzeniach.</p>
        @else
            <p>Powiadomienia poza Kuking są włączone na {{ $urzadzenia->count() }} {{ \App\Support\Odmiana::rzeczownik($urzadzenia->count(), 'urządzeniu', 'urządzeniach', 'urządzeniach') }}:</p>
            <ul>
                @foreach($urzadzenia as $urzadzenie)
                    <li>{{ $urzadzenie->nazwaPrzegladarki() }} — włączone {{ \App\Support\Czas::data($urzadzenie->created_at, 'j F Y') }}</li>
                @endforeach
            </ul>
            <form method="POST" action="{{ route('settings.notifications.disable-all') }}" class="mt-4">
                @csrf @method('DELETE')
                <button class="btn btn-secondary" type="submit">Wyłącz na wszystkich urządzeniach</button>
            </form>
        @endif
    </section>

    <section class="mt-8">
        <h2>Kiedy możemy dać znać</h2>
        <p>
            W czasie ciszy nocnej nic nie wysyłamy — to, co się wydarzy, dostaniesz rano.
            Gdy wyczerpie się dzienny limit, reszta poczeka do następnego dnia, zebrana w jedno powiadomienie.
            W Kuking wszystko widać od razu.
        </p>

        <form class="panel-formularza" method="POST" action="{{ route('settings.notifications.update') }}">
            @csrf @method('PUT')

            @php
                $godziny = range(0, 23);
                $wybor = fn (string $pole): int => (int) old($pole, $ustawienia[$pole]);
            @endphp

            <div class="field @error('cisza_od') has-error @enderror">
                <label for="f-cisza_od">Cisza nocna od godziny</label>
                <select class="field-input" id="f-cisza_od" name="cisza_od"
                        @error('cisza_od') aria-invalid="true" aria-describedby="f-cisza_od-error" @enderror>
                    @foreach($godziny as $godzina)
                        <option value="{{ $godzina }}" @selected($wybor('cisza_od') === $godzina)>{{ $godzina }}:00</option>
                    @endforeach
                </select>
                @error('cisza_od')<span class="field-error" id="f-cisza_od-error">{{ $message }}</span>@enderror
            </div>

            <div class="field @error('cisza_do') has-error @enderror">
                <label for="f-cisza_do">Cisza nocna do godziny</label>
                <select class="field-input" id="f-cisza_do" name="cisza_do"
                        @error('cisza_do') aria-invalid="true" aria-describedby="f-cisza_do-error" @enderror>
                    @foreach($godziny as $godzina)
                        <option value="{{ $godzina }}" @selected($wybor('cisza_do') === $godzina)>{{ $godzina }}:00</option>
                    @endforeach
                </select>
                <span class="field-help">Godziny liczymy według czasu polskiego. Ta sama godzina w obu polach znaczy: bez ciszy nocnej.</span>
                @error('cisza_do')<span class="field-error" id="f-cisza_do-error">{{ $message }}</span>@enderror
            </div>

            <div class="field @error('dzienny_limit') has-error @enderror">
                <label for="f-dzienny_limit">Najwyżej tyle powiadomień dziennie</label>
                <select class="field-input" id="f-dzienny_limit" name="dzienny_limit"
                        @error('dzienny_limit') aria-invalid="true" aria-describedby="f-dzienny_limit-error" @enderror>
                    @foreach($limity as $limit)
                        <option value="{{ $limit }}" @selected($wybor('dzienny_limit') === $limit)>{{ $limit }}</option>
                    @endforeach
                </select>
                @error('dzienny_limit')<span class="field-error" id="f-dzienny_limit-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Zapisz</button>
            </div>
        </form>
    </section>

    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="notifications" />
    </x-slot:rail>
</x-layout>
