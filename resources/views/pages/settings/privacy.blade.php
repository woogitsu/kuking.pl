<x-layout title="Prywatność" :noindex="true">
    <h1>Prywatność</h1>

    <x-error-summary />

    <form class="panel-formularza" method="POST" action="{{ route('settings.privacy') }}">
        @csrf @method('PUT')
        {{--
            OLD() PRZED WARTOŚCIĄ Z BAZY — ALE NIE SAMO OLD() (issue #792).

            Przy odrzuconym żądaniu formularz ma pokazać to, co człowiek
            PRZED CHWILĄ wybrał, nie to, co wciąż stoi w bazie — inaczej
            wygląda to tak, jakby zmiana w ogóle nie dotarła.

            Samo `old('pole', $zBazy)` TO NIE WYSTARCZA dla checkboxa:
            odznaczony checkbox w ogóle nie przychodzi w żądaniu, więc
            `old('pole')` wraca `null` w DWÓCH różnych sytuacjach — przy
            pierwszym GET (nie było żadnego formularza) i przy odznaczeniu
            (był formularz, ale bez tego pola) — i nie da się ich odróżnić
            bez sprawdzenia, czy sesja w ogóle niesie stare dane.
            `session()->hasOldInput()` (bez argumentu) mówi, czy WYSTĄPIŁO
            poprzednie żądanie: gdy tak, brak pola ma znaczyć odznaczone,
            a nie „sięgnij do bazy".
        --}}
        {{--
            STAN Z CHWILI OTWARCIA FORMULARZA (#879/#882).

            Zapis prywatności niesie DOWÓD ZGODY, nie samą preferencję —
            więc formularz otwarty wczoraj nie może dziś po cichu zapisać
            człowieka z powrotem na tygodniowy list ani cofnąć nowszej
            decyzji podjętej gdzie indziej (odnośnik „wypisz się" na dole
            e-maila, druga karta w przeglądarce). Te dwa ukryte pola niosą
            stan WIDZIANY przy renderowaniu; `UpdatePrivacySettings`
            porównuje je z bazą pod blokadą i przy rozjeździe nie zapisuje
            NICZEGO — ani połowy formularza, ani nieaktualnej zgody.

            Po błędzie wartość zostaje ze starego wysłania (`old()`), bo
            nowy stan trzeba ZOBACZYĆ, a nie dostać po cichu podstawiony —
            dlatego pod spodem jest odnośnik „Otwórz aktualne ustawienia".
        --}}
        @if($errors->any())
            <p><a href="{{ route('settings.privacy') }}">Otwórz aktualne ustawienia</a>, sprawdź oba wybory i zapisz je ponownie.</p>
        @endif
        <input type="hidden" name="original_digest" value="{{ old('original_digest', session()->hasOldInput() ? '' : (int) auth()->user()->wants_weekly_digest) }}">
        <input type="hidden" name="original_memories" value="{{ old('original_memories', session()->hasOldInput() ? '' : (int) auth()->user()->memories_enabled) }}">
        <div class="field @if($errors->hasAny(['wants_weekly_digest', 'original_digest'])) has-error @endif">
            <label class="choice" for="f-wants_weekly_digest" id="f-original_digest">
                <input id="f-wants_weekly_digest" type="checkbox" name="wants_weekly_digest" value="1"
                       @if($errors->hasAny(['wants_weekly_digest', 'original_digest'])) aria-invalid="true" aria-describedby="f-wants_weekly_digest-error" @endif
                       @checked(session()->hasOldInput() ? old('wants_weekly_digest', false) : auth()->user()->wants_weekly_digest)>
                <span>
                    <span class="choice-label">Chcę raz w tygodniu dostawać e-mail z Kuking</span>
                    <span class="choice-help">Krótkie podsumowanie: kto ugotował z Twoich przepisów, kto zaczął Cię obserwować i co pokazali ludzie, których obserwujesz. Jeden e-mail tygodniowo, nigdy więcej — i tylko wtedy, gdy naprawdę jest o czym pisać. Wypisać się możesz jednym kliknięciem na dole każdego e-maila, bez logowania.</span>
                </span>
            </label>
            @if($errors->hasAny(['wants_weekly_digest', 'original_digest']))
                <span class="field-error" id="f-wants_weekly_digest-error">{{ $errors->first('wants_weekly_digest') ?: $errors->first('original_digest') }}</span>
            @endif
        </div>

        {{--
            WSPOMNIENIA — WYŁĄCZNIK, KTÓRY MUSI BYĆ ŁATWY DO ZNALEZIENIA (issue #34).

            To nie jest ustawienie wygody. Wpis z przepisem po mamie, która
            zmarła w tym roku, wyświetlony bez ostrzeżenia na stronie głównej,
            jest okrutny. Człowiek w żałobie ma to wyłączyć jednym kliknięciem,
            a nie odklikiwać wspomnienia po kolei.

            Domyślnie włączone: funkcja, którą trzeba najpierw włączyć, nie
            istnieje dla nikogo poza tym, kto o niej wie — a to jest mechanika
            dla osoby gotującej od czterdziestu lat, nie dla osoby, która czyta
            ustawienia.
        --}}
        <div class="field @if($errors->hasAny(['memories_enabled', 'original_memories'])) has-error @endif mt-4">
            <label class="choice" for="f-memories_enabled" id="f-original_memories">
                <input id="f-memories_enabled" type="checkbox" name="memories_enabled" value="1"
                       @if($errors->hasAny(['memories_enabled', 'original_memories'])) aria-invalid="true" aria-describedby="f-memories_enabled-error" @endif
                       @checked(session()->hasOldInput() ? old('memories_enabled', false) : auth()->user()->memories_enabled)>
                <span>
                    <span class="choice-label">Przypominaj mi moje wpisy z tego dnia w poprzednich latach</span>
                    <span class="choice-help">Na stronie głównej pojawia się wtedy jeden Twój dawny wpis z tego samego dnia. Możesz to wyłączyć w każdej chwili — a pojedyncze wspomnienie schować przyciskiem przy nim.</span>
                </span>
            </label>
            @if($errors->hasAny(['memories_enabled', 'original_memories']))
                <span class="field-error" id="f-memories_enabled-error">{{ $errors->first('memories_enabled') ?: $errors->first('original_memories') }}</span>
            @endif
        </div>

        <button class="btn btn-primary mt-4" type="submit">Zapisz</button>
    </form>

    <section class="mt-8">
        <h2>Zablokowane osoby</h2>
        @if($blocked->isEmpty())
            <p class="meta">Nikogo nie blokujesz.</p>
        @else
            <p>Te osoby nie widzą Twoich treści, a Ty nie widzisz ich.</p>
            <div class="stack-tight">
                @foreach($blocked as $person)
                    <div class="card flex items-center gap-3 flex-wrap">
                        <x-avatar :user="$person" :size="44" />
                        <span class="flex-1">{{ $person->displayName() }}</span>
                        <form method="POST" action="{{ route('social.unblock', $person->profile->username) }}">
                            @csrf @method('DELETE')
                            {{-- #793: nazwa użytkownika w adresie mogła między
                                 wyrenderowaniem tej listy a kliknięciem trafić
                                 do kogoś innego (zwolniona i zajęta ponownie).
                                 Kontroler porównuje ten identyfikator
                                 z osobą, którą dziś naprawdę wskazuje nazwa. --}}
                            <input type="hidden" name="oczekiwany_id" value="{{ $person->getKey() }}">
                            <button class="btn btn-secondary" type="submit">Zdejmij blokadę</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section class="mt-8">
        <h2>Kto widzi Twoje treści</h2>
        <p>
            {{-- „sama decydujesz" przypisywało czytelnikowi rodzaj żeński
                 (issue #38, COPY_STYLE.md §2) — „sama" nie wnosi tu
                 informacji, więc zdanie działa i bez niego. --}}
            Przy każdym wpisie i przepisie decydujesz Ty: wszyscy, tylko osoby które Cię obserwują,
            albo tylko Ty. Możesz to zmienić w każdej chwili.
        </p>
    </section>

    {{-- Spis „Wszystkie ustawienia" w prawej szynie, nie pod formularzem —
         uzasadnienie i próg szerokości: components/ustawienia-nawigacja.blade.php. --}}
    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="privacy" />
    </x-slot:rail>
</x-layout>
