<x-layout title="Czytelność" :noindex="true">
    <h1>Czytelność</h1>

    <h2 class="text-title-sm mb-3">Rozmiar tekstu</h2>
    <p class="mb-5">
        Wybierz rozmiar, przy którym czyta Ci się wygodnie. Ustawienie zapisze się na Twoim koncie —
        będzie takie samo na telefonie, tablecie i komputerze.
    </p>

    <form class="card" method="POST" action="{{ route('settings.accessibility') }}">
        @csrf @method('PUT')

        <fieldset class="border-0 p-0">
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
                                @if($scale === 100) Zwykły @elseif($scale === 112) Trochę większy @elseif($scale === 125) Duży @else Bardzo duży @endif
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('text_scale')<span class="field-error">{{ $message }}</span>@enderror
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
    <p class="mb-5">
        Wybierz wygląd, w którym czyta Ci się wygodnie. Jasny jest domyślny
        dla każdego konta — ciemny włączasz sam, jeśli wolisz. Wybór zapisze
        się na Twoim koncie, tak samo jak rozmiar tekstu.
    </p>

    <form class="card" method="POST" action="{{ route('theme.update') }}">
        @csrf
        <fieldset class="border-0 p-0">
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
            @error('theme')<span class="field-error">{{ $message }}</span>@enderror
        </fieldset>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz</button>
        </div>
    </form>

    <section class="card mt-8">
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
