{{--
    „Ustaw termin” — termin, ilość i „mrożone” przy produkcie z listy
    „Co mam w domu” (#1903, D-333).

    Zwykły formularz, działa bez skryptu. Datę ustawia się na trzy sposoby:
    szybkim przyciskiem („Za 3 dni” — data liczona na serwerze od dziś),
    trzema listami wyboru (Dzień / Miesiąc z nazwą / Rok — bez kalendarza
    przeglądarki, którego nie da się dostosować do 18 px i 48 px; wzór:
    `pages/settings/birthday.blade.php`) albo odpowiedzią „Nie znam terminu”.

    `novalidate` + `x-error-summary` + błąd przy polu: poprawnie wpisane dane
    nie znikają (stan po błędzie z `old()`, tylko gdy wrócił TEN formularz —
    `_formularz`, bo brak pola `mrozone` znaczyłby inaczej „odznaczone”).

    Pierwszy przycisk w formularzu jest ukrytym „Zapisz”: to on odpowiada na
    Enter w polu „Ilość”. Bez niego Enter uruchamiałby pierwszy szybki przycisk
    i cicho ustawiał datę, której nikt nie wybrał.

    Terminy z przeszłości wolno wpisać — ktoś dopisuje produkt już po terminie.
    Uwaga pod formularzem nie pozwala odczytać terminu jako oceny produktu.
--}}
<x-layout :title="'Ustaw termin: '.$produkt->name" :noindex="true">
    <h1>Ustaw termin: {{ $produkt->name }}</h1>

    <p class="text-lead">
        Wpisz termin z opakowania, a produkt trafi na właściwe miejsce na liście.
        Tę informację widzisz tylko Ty.
    </p>

    <p data-stan-produktu><strong>{{ $stan }}</strong></p>

    <x-error-summary :fieldIds="['rodzaj' => 'f-rodzaj']" />

    @php
        $poBledzie = old('_formularz') === 'termin';
        $wybranyRodzaj = $poBledzie ? (string) old('rodzaj', '') : ($produkt->expiry_kind ?? '');
        $wybranyDzien = $poBledzie ? (string) old('termin_dzien', '') : (string) ($produkt->expires_on?->day ?? '');
        $wybranyMiesiac = $poBledzie ? (string) old('termin_miesiac', '') : (string) ($produkt->expires_on?->month ?? '');
        $wybranyRok = $poBledzie ? (string) old('termin_rok', '') : (string) ($produkt->expires_on?->year ?? '');
        $mrozone = $poBledzie ? (bool) old('mrozone', false) : $produkt->frozen;
        $bladRodzaju = $errors->first('rodzaj');
        $bladDnia = $errors->first('termin_dzien');
        $bladRoku = $errors->first('termin_rok');
    @endphp

    <form class="panel-formularza" method="POST" action="{{ route('pantry.update', $produkt) }}" novalidate>
        @csrf
        @method('PUT')
        <input type="hidden" name="_formularz" value="termin">
        <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">Zapisz</button>

        <fieldset class="border-0 p-0" id="f-rodzaj"
                  @if($bladRodzaju) tabindex="-1" aria-invalid="true" aria-describedby="f-rodzaj-error" @endif>
            <legend class="font-bold mb-3">Jaki to termin?</legend>
            <div class="choice-grid">
                @foreach($rodzaje as $wartosc => $etykieta)
                    <label class="choice">
                        <input type="radio" name="rodzaj" value="{{ $wartosc }}" @checked($wybranyRodzaj === $wartosc)>
                        <span>
                            <span class="choice-label">{{ $etykieta }}@if($wartosc === 'use_by') (termin przydatności)@endif</span>
                        </span>
                    </label>
                @endforeach
                <label class="choice">
                    <input type="radio" name="rodzaj" value="nieznany" @checked($wybranyRodzaj === 'nieznany')>
                    <span><span class="choice-label">Nie znam terminu</span></span>
                </label>
            </div>
            <x-blad-grupy name="rodzaj" />
        </fieldset>

        <h2 class="mt-8">Kiedy mija termin?</h2>

        <p class="mb-2">Dotknij jednego z przycisków — data zostanie policzona od dziś i zapisana od razu:</p>
        <div class="form-actions" data-szybkie-terminy>
            @foreach($szybkie as $wartosc => $etykieta)
                <button class="btn btn-secondary" type="submit" name="za" value="{{ $wartosc }}">{{ $etykieta }}</button>
            @endforeach
        </div>

        <p class="mt-6 mb-2">albo wybierz dokładną datę z list:</p>

        <div class="field @if($bladDnia) has-error @endif">
            <label for="f-termin_dzien">Dzień</label>
            <select class="field-input" id="f-termin_dzien" name="termin_dzien"
                    @if($bladDnia) aria-invalid="true" aria-describedby="f-termin_dzien-error" @endif>
                <option value="">— wybierz dzień —</option>
                @for($d = 1; $d <= 31; $d++)
                    <option value="{{ $d }}" @selected($wybranyDzien === (string) $d)>{{ $d }}</option>
                @endfor
            </select>
            @if($bladDnia)
                <span class="field-error" id="f-termin_dzien-error">{{ $bladDnia }}</span>
            @endif
        </div>

        <div class="field">
            <label for="f-termin_miesiac">Miesiąc</label>
            <select class="field-input" id="f-termin_miesiac" name="termin_miesiac">
                <option value="">— wybierz miesiąc —</option>
                @foreach(\App\Domain\Rocznice\Urodziny::MIESIACE as $numer => $nazwa)
                    <option value="{{ $numer }}" @selected($wybranyMiesiac === (string) $numer)>{{ $nazwa }}</option>
                @endforeach
            </select>
        </div>

        <div class="field @if($bladRoku) has-error @endif">
            <label for="f-termin_rok">Rok</label>
            <select class="field-input" id="f-termin_rok" name="termin_rok"
                    @if($bladRoku) aria-invalid="true" aria-describedby="f-termin_rok-error" @endif>
                <option value="">— wybierz rok —</option>
                @foreach($lata as $rok)
                    <option value="{{ $rok }}" @selected($wybranyRok === (string) $rok)>{{ $rok }}</option>
                @endforeach
            </select>
            @if($bladRoku)
                <span class="field-error" id="f-termin_rok-error">{{ $bladRoku }}</span>
            @endif
        </div>

        <x-field name="ilosc" label="Ilość" :value="$produkt->quantity_note" autocomplete="off"
                 help="Nie trzeba. Wpisz słowami, na przykład „pół kostki” albo „1 litr”. Najwyżej 40 znaków." />

        <div class="field">
            <label class="choice" for="f-mrozone">
                <input id="f-mrozone" type="checkbox" name="mrozone" value="1" @checked($mrozone)>
                <span>
                    <span class="choice-label">Mam to w zamrażarce</span>
                    <span class="choice-help">Produkt z zamrażarki nie trafia do sekcji „Zużyj w pierwszej kolejności”. Wpisany termin zostaje.</span>
                </span>
            </label>
        </div>

        <p class="meta">
            Termin to Twoja notatka z opakowania. Kuking nie ocenia, czy produkt nadaje się do jedzenia.
            Sprawdź go przed użyciem.
        </p>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Zapisz</button>
            @if($produkt->expires_on !== null)
                <button class="btn btn-secondary" type="submit" name="wyczysc" value="1">Wyczyść termin</button>
            @endif
            <a class="btn btn-quiet" href="{{ route('pantry.index') }}">Wróć do listy</a>
        </div>
    </form>
</x-layout>
