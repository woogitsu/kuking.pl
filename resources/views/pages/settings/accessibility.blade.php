<x-layout title="Czytelność" :noindex="true">
    <h1>Rozmiar tekstu</h1>
    <p style="margin-bottom:var(--spacing-5);">
        Wybierz rozmiar, przy którym czyta Ci się wygodnie. Ustawienie zapisze się na Twoim koncie —
        będzie takie samo na telefonie, tablecie i komputerze.
    </p>

    <form class="card" method="POST" action="{{ route('settings.accessibility') }}">
        @csrf @method('PUT')

        <fieldset style="border:0; padding:0;">
            <legend style="font-weight:700; margin-bottom:var(--spacing-3);">Rozmiar tekstu</legend>
            <div class="stack-tight">
                @foreach($scales as $scale)
                    <label class="choice">
                        <input type="radio" name="text_scale" value="{{ $scale }}" @checked($current === $scale)>
                        <span>
                            {{-- Podgląd w realnym rozmiarze — najlepszy sposób wyboru
                                 dla kogoś, kto nie myśli w procentach. --}}
                            <span class="choice-label" style="font-size:{{ $scale }}%;">
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

    <section class="card" style="margin-top:var(--spacing-8);">
        <h2>Można jeszcze więcej</h2>
        <p>
            Jeśli to wciąż za mało, powiększ całą stronę w przeglądarce:
            na komputerze przytrzymaj <kbd>Ctrl</kbd> i naciśnij <kbd>+</kbd>,
            na telefonie zmień rozmiar czcionki w ustawieniach systemu.
            Kuking działa poprawnie także wtedy.
        </p>
        <p style="margin-bottom:0;">
            Jasny i ciemny wygląd dobierają się same, według ustawień Twojego telefonu lub komputera.
        </p>
    </section>
</x-layout>
