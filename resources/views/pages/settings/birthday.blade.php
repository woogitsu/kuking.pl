{{--
    URODZINY — DZIEŃ I MIESIĄC, BEZ ROKU (issue #1755).

    Pole jest opcjonalne i prywatne. Roku nie ma celowo: do życzeń nie jest
    potrzebny, a pełna data urodzenia stoi na liście danych, których nie
    zbieramy (docs/SECURITY_PRIVACY_LEGAL.md). Zwykły formularz z dwiema
    listami wyboru — działa bez JavaScriptu.
--}}
<x-layout title="Urodziny" :noindex="true">
    <h1>Urodziny</h1>

    <p>
        Jeśli chcesz, podaj dzień i miesiąc urodzin. Roku nie potrzebujemy.
        Datę widzisz tylko Ty — nie ma jej na Twoim profilu.
    </p>

    <x-error-summary />

    @php
        $bladDnia = $errors->first('birthday_day');
        $bladMiesiaca = $errors->first('birthday_month');
        $wybranyDzien = (string) old('birthday_day', $user->birthday_day);
        $wybranyMiesiac = (string) old('birthday_month', $user->birthday_month);
    @endphp

    <form class="panel-formularza" method="POST" action="{{ route('settings.birthday.update') }}">
        @csrf @method('PUT')

        @if($dataSlownie)
            <p>Zapisana data: <strong>{{ $dataSlownie }}</strong>.</p>
        @endif

        <div class="field @if($bladDnia) has-error @endif">
            <label for="f-birthday_day">Dzień</label>
            <select class="field-input" id="f-birthday_day" name="birthday_day" required
                    @if($bladDnia) aria-invalid="true" aria-describedby="f-birthday_day-error" @endif>
                <option value="">— wybierz dzień —</option>
                @for($d = 1; $d <= 31; $d++)
                    <option value="{{ $d }}" @selected($wybranyDzien === (string) $d)>{{ $d }}</option>
                @endfor
            </select>
            @if($bladDnia)
                <span class="field-error" id="f-birthday_day-error">{{ $bladDnia }}</span>
            @endif
        </div>

        <div class="field @if($bladMiesiaca) has-error @endif">
            <label for="f-birthday_month">Miesiąc</label>
            <select class="field-input" id="f-birthday_month" name="birthday_month" required
                    @if($bladMiesiaca) aria-invalid="true" aria-describedby="f-birthday_month-error" @endif>
                <option value="">— wybierz miesiąc —</option>
                @foreach(\App\Domain\Rocznice\Urodziny::MIESIACE as $numer => $nazwa)
                    <option value="{{ $numer }}" @selected($wybranyMiesiac === (string) $numer)>{{ $nazwa }}</option>
                @endforeach
            </select>
            @if($bladMiesiaca)
                <span class="field-error" id="f-birthday_month-error">{{ $bladMiesiaca }}</span>
            @endif
        </div>

        <p class="meta">Urodziny 29 lutego obchodzimy 28 lutego w latach, w których 29 lutego nie ma.</p>

        <button class="btn btn-primary" type="submit">Zapisz</button>
    </form>

    @if($dataSlownie)
        {{--
            WYBORY PRZY DACIE — w tym samym miejscu co pole (research §5,
            zasada żałoby). Osobny formularz: przestawienie wyłącznika nie
            wymaga ponownego wybierania daty. Stan po błędzie z old() — ale tylko
            gdy wrócił TEN formularz (`_formularz`), bo błąd formularza daty
            też zostawia old() w sesji, a brak pola znaczyłby wtedy „odznaczone”.
        --}}
        <form class="panel-formularza mt-8" method="POST" action="{{ route('settings.birthday.preferences') }}">
            @csrf @method('PUT')
            <input type="hidden" name="_formularz" value="wybory">
            <h2>Co ma się dziać w dniu urodzin</h2>
            @php($poBledzieWyborow = old('_formularz') === 'wybory')

            <div class="field @error('birthday_wishes_enabled') has-error @enderror">
                <label class="choice" for="f-birthday_wishes_enabled">
                    <input id="f-birthday_wishes_enabled" type="checkbox" name="birthday_wishes_enabled" value="1"
                           @error('birthday_wishes_enabled') aria-invalid="true" aria-describedby="f-birthday_wishes_enabled-error" @enderror
                           @checked($poBledzieWyborow ? old('birthday_wishes_enabled', false) : $user->birthday_wishes_enabled)>
                    <span>
                        <span class="choice-label">Pokazuj mi życzenia od nas na stronie głównej</span>
                        <span class="choice-help">W dniu urodzin zobaczysz tam jedno zdanie z życzeniami od nas. Jeśli ten dzień jest dla Ciebie trudny, odznacz to pole.</span>
                    </span>
                </label>
                @error('birthday_wishes_enabled')
                    <span class="field-error" id="f-birthday_wishes_enabled-error">{{ $message }}</span>
                @enderror
            </div>

            <button class="btn btn-primary mt-4" type="submit">Zapisz wybory</button>
        </form>
    @endif

    @if($dataSlownie)
        {{-- „Usuń datę" odsunięte od zapisu i z potwierdzeniem bez JavaScriptu
             (AGENTS.md §5). Usunięcie wyłącza też wszystko, co od daty zależy. --}}
        <div class="danger-zone mt-8">
            <h2>Usunięcie daty</h2>
            <p>Po usunięciu nie zapamiętujemy Twoich urodzin. Możesz je podać ponownie w każdej chwili.</p>
            <x-confirm-button
                :action="route('settings.birthday.destroy')"
                label="Usuń datę"
                question="Na pewno usunąć datę urodzin?" />
        </div>
    @endif

    <x-slot:rail>
        <x-ustawienia-nawigacja aktywne="birthday" />
    </x-slot:rail>
</x-layout>
