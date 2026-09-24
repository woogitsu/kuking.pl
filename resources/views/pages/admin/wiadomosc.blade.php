{{-- DWA OSOBNE FORMULARZE, W TEJ KOLEJNOŚCI (D-058): najpierw odpowiedź
     (list wychodzi na zewnątrz i jest nieodwracalny), potem stan i notatka
     dla siebie (do poprawienia w każdej chwili). Każda trasa pyta Policy.

     JEDEN WSPÓLNY FORMULARZ BYŁBY BŁĘDEM, i to nie stylistycznym. Formularz
     z dwoma przyciskami `submit` ma przycisk DOMYŚLNY — pierwszy w kolejności
     drzewa. Enter wciśnięty na przycisku radio w „Stanie wiadomości” nie pyta,
     który to przycisk: wysyła formularz tym domyślnym, czyli WYSYŁA LIST.
     Osoba, która chciała tylko zaznaczyć stan, nie ma jak tego cofnąć.
     Rozdzielenie formularzy jest jedyną rzeczą, która to wyklucza bez
     JavaScriptu (AGENTS.md §5 pkt 3).

     SZKIC SĄSIADA NIE GINIE (issue #845). Przeglądarka wysyła tylko pola
     zatwierdzonego formularza, więc każdy z nich niesie UKRYTE KOPIE pól
     drugiego (`data-kopia-z`, wyłączone w znaczniku). Skrypt
     `resources/js/kopia-sasiedniego-pola.js` wypełnia je w chwili wysłania,
     a kontroler tylko odsyła je w `withInput()` — nigdy ich nie wykonuje.
     Bez skryptu kopie nie lecą i szkic sąsiada przepada jak dawniej
     (D-053: to wygoda, nie ochrona serwisu); list przez pomyłkę nie
     wychodzi w żadnym wariancie. --}}
<x-layout title="Wiadomość do nas — Panel moderacji" :noindex="true">
    <x-panel-moderacji ekran="Wiadomości do nas" />

    <p class="meta">
        <a href="{{ route('admin.contact') }}">← Wróć do listy wiadomości</a>
    </p>

    <h1>{{ $wiadomosc->rodzajLabel() }}</h1>

    <p class="meta">
        {{ \App\Support\Czas::data($wiadomosc->created_at, 'j F Y, H:i') }} ·
        {{ $wiadomosc->statusLabel() }}
        @if($wiadomosc->handler && $wiadomosc->handled_at)
            · {{ $wiadomosc->handler->displayName() }},
            {{ \App\Support\Czas::data($wiadomosc->handled_at, 'j F Y, H:i') }}
        @elseif($wiadomosc->handled_at)
            · obsługa Kuking,
            {{ \App\Support\Czas::data($wiadomosc->handled_at, 'j F Y, H:i') }}
        @endif
    </p>

    <article class="card">
        <p class="whitespace-pre-line mt-0 mb-0">{{ $wiadomosc->message }}</p>
    </article>

    <div class="sekcja-strony mt-5">
        <h2 class="mt-0">Skąd to przyszło</h2>
        <ul>
            <li>
                Kto:
                @if($wiadomosc->author)
                    {{ $wiadomosc->author->displayName() }}
                    (<a href="{{ route('profile.show', $wiadomosc->author->profile->username) }}">profil</a>)
                @else
                    osoba bez konta
                @endif
            </li>
            <li>
                Adres do odpowiedzi:
                @if($wiadomosc->adresDoOdpowiedzi())
                    <strong>{{ $wiadomosc->adresDoOdpowiedzi() }}</strong>
                @else
                    <strong>nie ma adresu</strong> — ta osoba nie zostawiła kontaktu
                    i nie da się jej odpisać pocztą.
                @endif
            </li>
            <li>
                Strona, z której pisano:
                @if($wiadomosc->page_path)
                    <span class="kod-do-przepisania">{{ $wiadomosc->page_path }}</span>
                @else
                    nie wiadomo
                @endif
            </li>
            <li>Wydanie serwisu: {{ $wiadomosc->wydanie ?? 'nie wiadomo' }}</li>
        </ul>
    </div>

    <x-error-summary />

    {{--
        ═══════════════════════════════════════════════════════════════════
         ODPOWIEDŹ DO CZŁOWIEKA (D-058)
        ═══════════════════════════════════════════════════════════════════
    --}}
    {{-- WARSTWA ZALEŻY OD TEGO, CZY JEST TU CO WYPEŁNIĆ.

         `panel-formularza` ma mocną obwódkę — tę samą, którą mają pola — więc
         obiecuje, że w środku coś się wpisuje. Gdy osoba nie zostawiła adresu,
         w tym bloku nie ma ani jednego pola, tylko zdanie „Nie ma jak
         odpisać". Obwódka obiecywałaby wtedy formularz, którego nie ma —
         czyli to samo, przed czym broni zakaz martwego przycisku (D-053),
         tylko w warstwie powierzchni. W tym wariancie blok jest sekcją.

         Gdy adres jest, blok zostaje panelem mimo historii wysłanych
         odpowiedzi w środku: po to się na ten ekran wchodzi (D-058). --}}
    <section @class([
        'mt-5',
        'panel-formularza' => (bool) $wiadomosc->adresDoOdpowiedzi(),
        'sekcja-strony' => ! $wiadomosc->adresDoOdpowiedzi(),
    ])>
        <h2 class="mt-0">Odpowiedz tej osobie</h2>

        {{--
            TERMIN USUNIĘCIA I DROGA PONOWNEGO OTWARCIA (D-… , issue #847).

            STAN ZASTANY: ten formularz był dostępny także przy sprawie
            ZAMKNIĘTEJ, a ekran nigdzie nie mówił, kiedy retencja skasuje
            całą sprawę razem z odpowiedzią dopisaną tutaj — kaskada
            `contact_message_replies` → `contact_messages` zabiera odpowiedź
            razem ze sprawą, bez osobnego ostrzeżenia. Ktoś dopisywał
            wyjaśnienie do starej zamkniętej sprawy, a NASTĘPNEGO DNIA
            sprzątanie (`kuking:sprzataj-wiadomosci`) kasowało obie rzeczy
            naraz.

            DECYZJA WŁAŚCICIELA (20.09.2026): pokazać KONKRETNY termin
            (datę, nie „wkrótce") i drogę ponownego otwarcia sprawy. WPROST
            ODRZUCONE: przesuwanie retencji od ostatniej odpowiedzi — to
            byłaby nowa decyzja o okresie przechowywania danych, wymagająca
            zmiany polityki prywatności, nie poprawka ekranu.

            Data pochodzi z `PrzedawnioneWiadomosciDoOperatora::terminUsuniecia()`
            — TEJ SAMEJ metody, z której korzysta samo sprzątanie (`posprzataj()`
            liczy próg tym samym `subMonthsNoOverflow`), więc ekran nie może
            zacząć kłamać przez rozjazd dwóch osobnych reguł liczenia tej
            samej daty. `$terminUsuniecia` jest `null`, gdy sprawa jest
            otwarta — otwartej sprawy retencja nigdy nie rusza, więc nie ma
            czym straszyć.

            Data pokazana w STREFIE CZŁOWIEKA (`Czas::data`, issue #746;
            wewnątrz baza i sprzątanie liczą w UTC), inaczej termin o złej
            porze byłby drugą wersją tego samego błędu.
        --}}
        @if($terminUsuniecia !== null)
            <p class="notice mb-4" role="status">
                <strong>Ta sprawa jest zamknięta.</strong>
                Zostanie skasowana automatycznie i bezpowrotnie
                <strong>{{ \App\Support\Czas::data($terminUsuniecia, 'j F Y, H:i') }}</strong>
                — razem z każdą odpowiedzią dopisaną tutaj, także tą, którą
                napiszesz teraz.
                Jeśli ta odpowiedź ma zostać, <a href="#stan-wiadomosci">otwórz sprawę ponownie</a>
                (zaznacz „W trakcie" w polu „Stan wiadomości" niżej i zapisz)
                — zanim minie ten termin.
            </p>
        @endif

        @if($wiadomosc->odpowiedzi->isNotEmpty())
            <h3 class="text-title-sm">Co już wyszło</h3>

            @foreach($wiadomosc->odpowiedzi as $odpowiedz)
                {{-- Ramka na każdej odpowiedzi, bo dwa listy pod sobą bez
                     granicy zlewają się w jedną ścianę tekstu — zwłaszcza
                     przy powiększonej czcionce (ten sam powód co
                     `.wizard-row` przy krokach przepisu). --}}
                <article class="wizard-row mb-4">
                    <p class="meta mt-0">
                        {{ \App\Support\Czas::data($odpowiedz->created_at, 'j F Y, H:i') }} ·
                        <strong>{{ $odpowiedz->statusLabel() }}</strong>
                        @if($odpowiedz->author)
                            · {{ $odpowiedz->author->displayName() }}
                        @else
                            · obsługa Kuking
                        @endif
                    </p>

                    <p class="whitespace-pre-line">{{ $odpowiedz->body }}</p>

                    {{-- STAN WYSYŁKI POWIEDZIANY WPROST, ZDANIEM, NIE KOLOREM.
                         „Wysłana" bez daty niczego by nie rozstrzygało, a przy
                         porażce i przy stanie nieustalonym trzeba napisać, CO
                         ZROBIĆ — inaczej moderator uzna sprawę za zamkniętą
                         i człowiek nigdy nie dostanie odpowiedzi (issue #234). --}}
                    @if($odpowiedz->status === \App\Models\ContactMessageReply::STATUS_WYSLANA)
                        <p class="meta mb-0">
                            Poczta przyjęła tę wiadomość
                            {{ \App\Support\Czas::data($odpowiedz->sent_at, 'j F Y, H:i') }}.
                        </p>
                    @elseif($odpowiedz->status === \App\Models\ContactMessageReply::STATUS_NIEUDANA)
                        <p class="notice mb-0" role="status">
                            <strong>Ta wiadomość NIE wyszła.</strong>
                            Napisz odpowiedź jeszcze raz w polu niżej (możesz przekleić tekst
                            z góry) albo odpisz z własnej poczty.
                            @if($odpowiedz->error)
                                <br>Poczta odmówiła tak: <span class="kod-do-przepisania">{{ $odpowiedz->error }}</span>
                            @endif
                        </p>
                    @else
                        <p class="notice mb-0" role="status">
                            <strong>Nie wiadomo, czy ta wiadomość wyszła.</strong>
                            Wysyłka została przerwana w połowie. Sprawdź skrzynkę
                            {{ config('kuking.community.contact_email') }} albo panel dostawcy poczty,
                            zanim wyślesz to samo drugi raz.
                        </p>
                    @endif
                </article>
            @endforeach
        @endif

        @if($wiadomosc->adresDoOdpowiedzi())
            <form id="odpowiedz-formularz" method="POST" action="{{ route('admin.contact.reply', $wiadomosc) }}">
                @csrf
                {{-- Kopie niezapisanego stanu i notatki (issue #845) — patrz
                     komentarz na górze pliku. Wysyłka ich nie zapisuje. --}}
                <input type="hidden" name="status" data-kopia-z="#stan-wiadomosci [name=status]:checked" disabled>
                <input type="hidden" name="handler_note" data-kopia-z="#stan-wiadomosci [name=handler_note]" disabled>
                <input type="hidden" name="version" data-kopia-z="#stan-wiadomosci [name=version]" disabled>
                @php($attempt = $wiadomosc->odpowiedzi->firstWhere('reply_key', old('reply_key', '')))
                <input type="hidden" name="reply_key" value="{{ old('reply_key', (string) \Illuminate\Support\Str::uuid()) }}">
                @error('reply_key')<p class="field-error" id="f-reply_key" tabindex="-1">{{ $message }}</p>@enderror

                <x-field name="odpowiedz" label="Treść odpowiedzi" type="textarea" :rows="8"
                         :required="true"
                         :value="old('odpowiedz')"
                         help="Ten tekst dostanie człowiek w e-mailu, dokładnie taki, jak go napiszesz." />

                {{-- `.form-actions` daje odstęp od pola (margin-top: spacing-8).
                     Przycisk postawiony tuż pod polem czyta się jak jego część,
                     a przy powiększonym tekście łatwo go trafić palcem, celując
                     w koniec pisania. --}}
                <div class="form-actions">
                    @if($attempt)
                        <p>To będzie osobny list. Przy nieustalonym wyniku najpierw sprawdź u dostawcy, czy poprzedni został przyjęty.</p>
                        <button class="btn btn-primary" type="submit" name="reply_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">Wyślij jako nową odpowiedź</button>
                    @else
                        <button class="btn btn-primary" type="submit">Wyślij odpowiedź</button>
                    @endif
                </div>
            </form>

            <p class="meta">
                Wiadomość wyjdzie od serwisu (<strong>{{ config('mail.from.address') }}</strong>),
                nie z Twojej prywatnej poczty. Odpowiedź tej osoby wróci na
                <strong>{{ config('kuking.community.contact_email') }}</strong> —
                nie na ten ekran, bo Kuking poczty nie odbiera.
            </p>

            <p class="meta">
                Wysyłamy od razu, w tym kliknięciu. Jeśli poczta odmówi, zobaczysz to tutaj,
                a wpisany tekst zostanie w polu.
            </p>

            {{--
                `mailto:` ZOSTAJE JAKO DROGA AWARYJNA, nie jako druga główna
                droga (uzasadnienie: D-058). Jest odsunięty od formularza
                i nazwany tym, czym jest — inaczej byłby zaproszeniem do
                rozjazdu („odpisałem z Gmaila i zapomniałem odhaczyć").
            --}}
            <details class="mt-5">
                {{-- `summary` jako przycisk, tak jak w ustawieniach konta:
                     pole kliknięcia ma mieć 48 px, a nie wysokość linijki
                     tekstu (docs/UX_50_PLUS.md). --}}
                <summary class="btn btn-secondary inline-flex">Poczta nie działa albo trzeba wysłać załącznik</summary>
                <p class="mt-4">
                    Wtedy odpisz ze swojego programu poczty na
                    <a href="mailto:{{ $wiadomosc->adresDoOdpowiedzi() }}">{{ $wiadomosc->adresDoOdpowiedzi() }}</a>
                    i zapisz w notatce niżej treść odpowiedzi — bo tej drogi serwis nie widzi
                    i nie pokaże jej w historii wyżej.
                </p>
            </details>
        @else
            <p class="notice" role="status">
                <strong>Nie ma jak odpisać tej osobie.</strong>
                Nie zostawiła adresu e-mail (albo jej konto zostało usunięte). Jeśli sprawa
                jest do zamknięcia, oznacz wiadomość jako załatwioną i zapisz w notatce,
                co z niej wynikło.
            </p>
        @endif
    </section>

    <form id="stan-wiadomosci" class="panel-formularza mt-5" method="POST" action="{{ route('admin.contact.update', $wiadomosc) }}">
        @csrf
        @if($wiadomosc->adresDoOdpowiedzi())
            {{-- Kopia szkicu odpowiedzi (issue #845). Zapis stanu NIE wysyła
                 listu — kontroler tylko odda ten tekst z powrotem do pola. --}}
            <input type="hidden" name="odpowiedz" data-kopia-z="#odpowiedz-formularz [name=odpowiedz]" disabled>
            <input type="hidden" name="reply_key" data-kopia-z="#odpowiedz-formularz input[type=hidden][name=reply_key]" disabled>
        @endif
        <input type="hidden" name="version" value="{{ $errors->has('version') ? $wiadomosc->version : old('version', $wiadomosc->version) }}">
        <div id="f-version" tabindex="-1">
            @error('version')
                <p class="notice" role="alert">{{ $message }}</p>
                <p>Bieżący stan: <strong>{{ $wiadomosc->statusLabel() }}</strong>.</p>
                <p>Bieżąca notatka:</p>
                <p class="whitespace-pre-line">{{ $wiadomosc->handler_note ?? 'Brak notatki.' }}</p>
                <p>Twój tekst pozostał w polu „Notatka dla siebie”.</p>
            @enderror
        </div>

        <fieldset class="border-0 p-0">
            <legend class="font-bold mb-3">Stan wiadomości</legend>

            {{-- Wysłanie odpowiedzi ŚWIADOMIE nie przestawia stanu — patrz
                 `WiadomosciController::odpowiedz()`. Skoro tak, ekran musi to
                 powiedzieć, zamiast pozwolić moderatorowi odkryć to samemu
                 po tygodniu leżenia sprawy w „Nowych". --}}
            <p class="meta">
                Wysłanie odpowiedzi nie zmienia stanu. Jeśli sprawa jest zamknięta,
                zaznacz „Załatwiona" — od tej chwili liczy się retencja.
            </p>
            <div class="choice-grid">
                @foreach(\App\Models\ContactMessage::STATUSY as $wartosc => $etykieta)
                    <label class="choice">
                        <input type="radio" name="status" value="{{ $wartosc }}"
                               @if($loop->first) id="f-status" @endif
                               @error('status') aria-invalid="true" aria-describedby="f-status-error" @enderror
                               @checked(old('status', $wiadomosc->status) === $wartosc)>
                        <span class="choice-label">{{ $etykieta }}</span>
                    </label>
                @endforeach
            </div>
            @error('status')<span class="field-error" id="f-status-error">{{ $message }}</span>@enderror
        </fieldset>

        {{-- ETYKIETA BEZ „(nieobowiązkowe)" — tę adnotację dokłada sam
             `x-field` na podstawie `:required`. Dopisana ręcznie dublowała
             się na ekranie. --}}
        <x-field name="handler_note" label="Notatka dla siebie" type="textarea" :rows="4"
                 :value="old('handler_note', $wiadomosc->handler_note)"
                 help="Widzi ją tylko obsługa. Na przykład: numer issue. Wysłanych odpowiedzi nie musisz tu przepisywać — są zapisane wyżej." />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz</button>
        </div>
    </form>

    <p class="meta mt-5">
        Ta wiadomość zniknie z bazy sama, {{ config('kuking.kontakt.retention_months') }}
        {{ \App\Support\Odmiana::rzeczownik((int) config('kuking.kontakt.retention_months'), 'miesiąc', 'miesiące', 'miesięcy') }}
        po oznaczeniu jako załatwiona. Dopóki jest otwarta, nie kasuje jej nic.
    </p>
</x-layout>
