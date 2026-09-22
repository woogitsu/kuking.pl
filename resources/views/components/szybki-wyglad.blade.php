@props(['scale', 'theme'])
<details class="szybki-wyglad" data-szybki-wyglad>
    <summary><span aria-hidden="true">Aa · </span>Wygląd</summary>
    <section class="szybki-wyglad-panel" aria-labelledby="szybki-wyglad-tytul">
        <h2 id="szybki-wyglad-tytul">Dopasuj wygląd</h2>
        <form method="POST" action="{{ route('theme.update') }}">
            @csrf
            <label for="szybka-skala">Rozmiar tekstu</label>
            <p class="szybki-wyglad-info">Poniżej 100% zmniejszamy też odstępy. Przyciski pozostają wygodne do dotknięcia.</p>
            <div class="szybki-wyglad-skala">
                <button class="btn btn-secondary" type="button" data-skala-krok="-1" aria-label="Zmniejsz tekst" hidden>A−</button>
                <select id="szybka-skala" name="text_scale">
                    @foreach(config('kuking.text.scales') as $value)
                        <option value="{{ $value }}" @selected($scale === $value)>{{ $value }}% — {{ config('kuking.text.scale_labels.'.$value) }}</option>
                    @endforeach
                </select>
                <button class="btn btn-secondary" type="button" data-skala-krok="1" aria-label="Powiększ tekst" hidden>A+</button>
            </div>
            <label for="szybki-motyw">Wygląd strony</label>
            <select id="szybki-motyw" name="theme">
                <option value="light" @selected($theme === 'light')>Jasny</option>
                <option value="dark" @selected($theme === 'dark')>Ciemny</option>
            </select>
            <p class="szybki-wyglad-info">@auth Zapisujemy wybór na Twoim koncie. @else Zapisujemy wybór w tej przeglądarce. @endauth</p>
            <p role="status" data-wyglad-status></p>
            <button class="btn btn-secondary" type="submit">Zapisz wygląd</button>
            <button class="btn btn-secondary" type="submit" name="reset_appearance" value="1">Przywróć domyślne</button>
            <button class="btn btn-secondary" type="button" data-wyglad-zamknij hidden>Zamknij</button>
        </form>
    </section>
</details>
<aside class="szybki-wyglad-podpowiedz" data-wyglad-podpowiedz hidden>
        <p>Dopasuj rozmiar tekstu i wygląd strony.</p>
        <button class="btn btn-secondary" type="button" data-wyglad-pomin>Rozumiem</button>
    </aside>
