{{--
    „Dodaj do planera” na stronie przepisu (#27, D-310).

    Ten sam wzorzec co „Wybierz zeszyt” (`wybor-zeszytu.blade.php`):
    `<details>` otwiera się bez skryptu, dni to lista radiowa z celem
    dotknięcia ≥ 48 px, a nie `<select>` i nie przeciąganie. Siedem
    najbliższych dni — dalsze planuje się na ekranie planera.

    Gdy formularz wróci z błędem (np. dzień ma komplet pozycji), panel
    otwiera się sam, a błąd stoi przy liście ORAZ w podsumowaniu.
--}}
@props(['recipe'])
@php
    $wiersz = 'planer-'.$recipe->getKey();
    $aktywny = \App\Support\WierszFormularza::jestAktywny($wiersz);
    $blad = $aktywny ? ($errors->first('day') ?: $errors->first('label')) : null;
    $dzis = \Carbon\CarbonImmutable::parse(\App\Support\Czas::dzisiajData());
    $wybrany = \App\Support\WierszFormularza::stareLubDomyslne('day', $wiersz, $dzis->toDateString());
    $id = 'f-day-'.$wiersz;
@endphp
<details class="wybor-dnia" @if($blad) open @endif>
    <summary class="btn btn-secondary">Dodaj do planera</summary>
    <div class="panel-formularza mt-3">
        <form method="POST" action="{{ route('planer.store') }}">
            @csrf
            <input type="hidden" name="_wiersz" value="{{ $wiersz }}">
            <input type="hidden" name="recipe_id" value="{{ $recipe->getKey() }}">
            @if($blad)
                <x-error-summary />
            @endif
            <fieldset class="field wybor-dnia-lista">
                <legend class="field-label">Na który dzień?</legend>
                @for($i = 0; $i < 7; $i++)
                    @php
                        $dzien = $dzis->addDays($i);
                        $przedrostek = match ($i) { 0 => 'Dziś — ', 1 => 'Jutro — ', default => '' };
                    @endphp
                    <label class="wybor-dnia-opcja">
                        <input type="radio" name="day" value="{{ $dzien->toDateString() }}"
                               @if($i === 0) id="{{ $id }}" @endif required
                               @checked($wybrany === $dzien->toDateString())
                               @if($blad) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
                        <span>{{ $przedrostek }}{{ \App\Domain\Planer\PlanerTygodnia::nazwaDnia($dzien) }}</span>
                    </label>
                @endfor
                @if($blad)
                    <p class="field-error" id="{{ $id }}-error">{{ $blad }}</p>
                @endif
            </fieldset>
            <button class="btn btn-secondary mt-3" type="submit">Dodaj na ten dzień</button>
        </form>
        <a class="inline-link mt-3" href="{{ route('planer.show') }}">Otwórz planer tygodnia</a>
    </div>
</details>
