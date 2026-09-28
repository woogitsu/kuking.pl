<x-layout title="Czytelność" :noindex="true">
    <h1>Czytelność</h1>

    {{-- Błąd przy grupie ORAZ w podsumowaniu na górze (AGENTS.md §5) — tak jak
         na pozostałych ekranach ustawień (audyt B1, znalezisko 6). --}}
    <x-error-summary />

    <h2 class="text-title-sm mb-3">Rozmiar tekstu</h2>
    <p class="mb-5">
        Wybierz rozmiar, przy którym czyta Ci się wygodnie. Ustawienie zapisze się na Twoim koncie —
        będzie takie samo na telefonie, tablecie i komputerze.
        Poniżej 100% zmniejszamy też odstępy. Przyciski pozostają wygodne do dotknięcia.
    </p>

    @php
        /*
         * Podpisy idą z konfiguracji (`kuking.text.scale_labels`), a nie
         * z łańcucha `@if` w Blade. Przy siedmiu rozmiarach ten łańcuch był
         * nie do przeczytania, a dyrektywa Blade przyklejona do tekstu bez
         * odstępu w ogóle się nie kompiluje — pułapka, na której już raz
         * stanęliśmy. Zapasowe `?? $scale.'%'` jest po to, żeby brak podpisu
         * pokazał się jako liczba, a nie jako pusty wiersz; testu to nie
         * zastępuje, bo procent w tym miejscu to usterka, tylko widoczna.
         */
        $podpisy = (array) config('kuking.text.scale_labels');
    @endphp

    <form class="panel-formularza" method="POST" action="{{ route('settings.accessibility') }}">
        @csrf @method('PUT')

        {{-- `id` jest CELEM odnośnika z podsumowania błędów, a atrybuty ARIA
             wiążą błąd z grupą — patrz `x-blad-grupy`. --}}
        <fieldset class="border-0 p-0" id="f-text_scale"
                  @error('text_scale') tabindex="-1" aria-invalid="true" aria-describedby="f-text_scale-error" @enderror>
            <legend class="font-bold mb-3">Rozmiar tekstu</legend>
            <div class="stack-tight">
                @foreach($scales as $scale)
                    <label class="choice">
                        <input type="radio" name="text_scale" value="{{ $scale }}" @checked($current === $scale)>
                        <span>
                            {{-- Podgląd w realnym rozmiarze — najlepszy sposób wyboru
                                 dla kogoś, kto nie myśli w procentach. --}}
                            <span class="choice-label" data-skala="{{ $scale }}">
                                Rosół na niedzielę wyszedł złoty.
                            </span>
                            <span class="choice-help">
                                {{ $podpisy[$scale] ?? $scale.'%' }}
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
            <x-blad-grupy name="text_scale" />
        </fieldset>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz</button>
        </div>
    </form>

    {{--
        KOLORY (jasny/ciemny) — właściciel zgłosił, że telefon sam przełączał
        wygląd na ciemny w nocy, choć nikt o to nie prosił.

        OSOBNY FORMULARZ, NIE JEDEN Z ROZMIAREM TEKSTU WYŻEJ
        Zapisuje przez `ThemeController`, dokładnie ten sam mechanizm co
        szybki przełącznik w stopce (widoczny też dla gościa) — jeden
        formularz na obie sprawy wymagałby, żeby submit tej strony ZAWSZE
        podawał oba pola naraz, a to złamałoby test, który zmienia sam
        rozmiar tekstu bez dotykania koloru.
    --}}
    <h2 class="text-title-sm mb-3 mt-8">Kolory</h2>
    {{-- „włączasz sam" przypisywało czytelnikowi rodzaj męski (issue #38,
         COPY_STYLE.md §2) — „sam" nie wnosi tu informacji, więc zdanie działa
         i bez niego. --}}
    <p class="mb-5">
        Wybierz wygląd, w którym czyta Ci się wygodnie. Jasny jest domyślny
        dla każdego konta — ciemny włączasz, jeśli wolisz. Wybór zapisze
        się na Twoim koncie, tak samo jak rozmiar tekstu.
    </p>

    <form class="panel-formularza" method="POST" action="{{ route('theme.update') }}">
        @csrf
        {{-- `id` jest CELEM odnośnika z podsumowania błędów, a atrybuty ARIA
             wiążą błąd z grupą — patrz `x-blad-grupy`. --}}
        <fieldset class="border-0 p-0" id="f-theme"
                  @error('theme') tabindex="-1" aria-invalid="true" aria-describedby="f-theme-error" @enderror>
            <legend class="font-bold mb-3">Wygląd</legend>
            <div class="stack-tight">
                @foreach($themeOptions as $option)
                    <label class="choice">
                        <input type="radio" name="theme" value="{{ $option }}" @checked($currentTheme === $option)>
                        <span>
                            <span class="choice-label">{{ $option === 'dark' ? 'Ciemny' : 'Jasny' }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            <x-blad-grupy name="theme" />
        </fieldset>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz</button>
        </div>
    </form>

    <section class="ramka-pomocnicza mt-8">
        <h2>Można jeszcze więcej</h2>
        <p class="mb-0">
            Jeśli to wciąż za mało, powiększ całą stronę w przeglądarce:
            na komputerze przytrzymaj <kbd>Ctrl</kbd> i naciśnij <kbd>+</kbd>,
            na telefonie zmień rozmiar czcionki w ustawieniach systemu.
            Kuking działa poprawnie także wtedy.
        </p>
    </section>

    {{-- Spis „Wszystkie ustawienia" w prawej szynie, nie pod formularzem —
         uzasadnienie i próg szerokości: components/ustawienia-nawigacja.blade.php. --}}
    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="accessibility" />
    </x-slot:rail>
</x-layout>
