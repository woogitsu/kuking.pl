<x-layout title="Prywatność" :noindex="true">
    <h1>Prywatność</h1>

    <form class="panel-formularza" method="POST" action="{{ route('settings.privacy') }}">
        @csrf @method('PUT')
        <x-error-summary />
        @if($errors->any())
            <p><a href="{{ route('settings.privacy') }}">Otwórz aktualne ustawienia</a>, sprawdź oba wybory i zapisz je ponownie.</p>
        @endif
        <input type="hidden" name="original_digest" value="{{ old('original_digest', session()->hasOldInput() ? '' : (int) auth()->user()->wants_weekly_digest) }}">
        <input type="hidden" name="original_memories" value="{{ old('original_memories', session()->hasOldInput() ? '' : (int) auth()->user()->memories_enabled) }}">
        <input type="hidden" name="wants_weekly_digest" value="0">
        <label class="choice" for="f-wants_weekly_digest" id="f-original_digest">
            <input id="f-wants_weekly_digest" type="checkbox" name="wants_weekly_digest" value="1" @checked(old('wants_weekly_digest', auth()->user()->wants_weekly_digest)) @if($errors->hasAny(['wants_weekly_digest', 'original_digest'])) aria-invalid="true" aria-describedby="f-wants_weekly_digest-error" @endif>
            <span>
                <span class="choice-label">Chcę raz w tygodniu dostawać e-mail z Kuking</span>
                <span class="choice-help">Krótkie podsumowanie: kto ugotował z Twoich przepisów, kto zaczął Cię obserwować i co pokazali ludzie, których obserwujesz. Jeden e-mail tygodniowo, nigdy więcej — i tylko wtedy, gdy naprawdę jest o czym pisać. Wypisać się możesz jednym kliknięciem na dole każdego e-maila, bez logowania.</span>
            </span>
        </label>
        @if($errors->hasAny(['wants_weekly_digest', 'original_digest']))
            <p id="f-wants_weekly_digest-error" class="field-error">{{ $errors->first('wants_weekly_digest') ?: $errors->first('original_digest') }}</p>
        @endif

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
        <input type="hidden" name="memories_enabled" value="0">
        <label class="choice mt-4" for="f-memories_enabled" id="f-original_memories">
            <input id="f-memories_enabled" type="checkbox" name="memories_enabled" value="1" @checked(old('memories_enabled', auth()->user()->memories_enabled)) @if($errors->hasAny(['memories_enabled', 'original_memories'])) aria-invalid="true" aria-describedby="f-memories_enabled-error" @endif>
            <span>
                <span class="choice-label">Przypominaj mi moje wpisy z tego dnia w poprzednich latach</span>
                <span class="choice-help">Na stronie głównej pojawia się wtedy jeden Twój dawny wpis z tego samego dnia. Możesz to wyłączyć w każdej chwili — a pojedyncze wspomnienie schować przyciskiem przy nim.</span>
            </span>
        </label>
        @if($errors->hasAny(['memories_enabled', 'original_memories']))
            <p id="f-memories_enabled-error" class="field-error">{{ $errors->first('memories_enabled') ?: $errors->first('original_memories') }}</p>
        @endif

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
