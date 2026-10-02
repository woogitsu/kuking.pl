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

    DWA OPAKOWANIA (#2568). Ten sam formularz edytuje pierwsze albo drugie
    opakowanie (`?opakowanie=drugie`). Nad formularzem stoi blok „Które
    opakowanie” z obydwoma opakowaniami słowami i znacznikiem „to edytujesz”;
    drugie opakowanie powstaje dopiero po „Dodaj drugie opakowanie” (zwykły
    zapis, retry bezpieczny). Przy dwóch opakowaniach formularz pierwszego
    niesie odcisk jego treści, a drugiego — identyfikator wiersza, żeby stary
    formularz nie zapisał się na opakowaniu, którego człowiek nie widział.

    Terminy z przeszłości wolno wpisać — ktoś dopisuje produkt już po terminie.
    Uwaga pod formularzem nie pozwala odczytać terminu jako oceny produktu.
--}}
@php
    $drugie = $cel === 'drugie';
    $maDwa = count($opakowania) > 1;
    $nowe = $drugie && $opakowanie === null;
    $oznaczone = $maDwa || $drugie;
    $bladOpakowania = $errors->first('opakowanie');
@endphp
<x-layout :title="'Ustaw termin: '.$produkt->name.($oznaczone ? ($drugie ? ' — drugie opakowanie' : ' — pierwsze opakowanie') : '')" :noindex="true">
    <h1>Ustaw termin: {{ $produkt->name }}</h1>

    <p class="text-lead">
        Wpisz termin z opakowania, a produkt trafi na właściwe miejsce na liście.
        Tę informację widzisz tylko Ty.
    </p>

    <x-error-summary :fieldIds="['rodzaj' => 'f-rodzaj']" />

    @if($oznaczone)
        <section class="sekcja-strony" id="f-opakowanie" tabindex="-1" aria-labelledby="ktore-opakowanie" data-ktore-opakowanie
                 @if($bladOpakowania) aria-invalid="true" aria-describedby="f-opakowanie-error" @endif>
            <h2 class="mt-0" id="ktore-opakowanie">
                @if($nowe) Dodajesz drugie opakowanie @else Edytujesz: {{ mb_strtolower($drugie ? 'Drugie opakowanie' : 'Pierwsze opakowanie') }} @endif
            </h2>
            @if($bladOpakowania)
                <p class="field-error" id="f-opakowanie-error">{{ $bladOpakowania }}</p>
            @endif
            <ul class="lista-naga stack-tight">
                @foreach($opakowania as $o)
                    <li data-opakowanie="{{ $o->numer }}">
                        <strong>{{ $o->etykieta() }}</strong>@if($o->numer === $cel) — to edytujesz @endif<br>
                        {{ $o->quantity_note ? $o->quantity_note.'. ' : '' }}{{ \App\Domain\Pantry\PriorytetZuzycia::opisStanu($o) }}
                        @if($o->numer !== $cel)
                            <br><a class="btn btn-secondary mt-2" href="{{ route('pantry.edit', ['pantryItem' => $produkt, 'opakowanie' => $o->numer]) }}"
                                   aria-label="Zmień termin: {{ $produkt->name }}, {{ mb_strtolower($o->etykieta()) }}">Zmień to opakowanie</a>
                        @endif
                    </li>
                @endforeach
                @if($nowe)
                    <li data-opakowanie="drugie"><strong>Drugie opakowanie</strong> — to dodajesz. Wpisz poniżej jego termin, ilość i czy jest w zamrażarce.</li>
                @endif
            </ul>
            <p class="meta">
                @if($nowe) Pierwsze opakowanie zostaje bez zmian.
                @else Zmiana dotyczy tylko tego opakowania — {{ $drugie ? 'pierwsze' : 'drugie' }} zostaje bez zmian.
                @endif
            </p>
        </section>
    @else
        <p data-stan-produktu><strong>{{ $stan }}</strong></p>
    @endif

    @php
        $poBledzie = old('_formularz') === 'termin';
        $wybranyRodzaj = $poBledzie ? (string) old('rodzaj', '') : ($opakowanie?->expiry_kind ?? '');
        $wybranyDzien = $poBledzie ? (string) old('termin_dzien', '') : (string) ($opakowanie?->expires_on?->day ?? '');
        $wybranyMiesiac = $poBledzie ? (string) old('termin_miesiac', '') : (string) ($opakowanie?->expires_on?->month ?? '');
        $wybranyRok = $poBledzie ? (string) old('termin_rok', '') : (string) ($opakowanie?->expires_on?->year ?? '');
        // Po błędzie „szybki przycisk bez rodzaju”: data z przycisku wraca na
        // listy (chyba że osoba wybrała już coś na listach — wtedy jej wybór).
        $dataZPrzycisku = $poBledzie && $wybranyDzien === '' && $wybranyMiesiac === '' && $wybranyRok === '' ? $dataZPrzycisku : null;
        if ($dataZPrzycisku !== null) {
            [$wybranyRok, $wybranyMiesiac, $wybranyDzien] = array_map('intval', explode('-', $dataZPrzycisku));
            [$wybranyRok, $wybranyMiesiac, $wybranyDzien] = [(string) $wybranyRok, (string) $wybranyMiesiac, (string) $wybranyDzien];
        }
        $mrozone = $poBledzie ? (bool) old('mrozone', false) : (bool) ($opakowanie?->frozen ?? false);
        $bladRodzaju = $errors->first('rodzaj');
        $bladDnia = $errors->first('termin_dzien');
        $bladRoku = $errors->first('termin_rok');
    @endphp

    <form class="panel-formularza" method="POST" action="{{ route('pantry.update', $produkt) }}" novalidate>
        @csrf
        @method('PUT')
        <input type="hidden" name="_formularz" value="termin">
        <input type="hidden" name="opakowanie" value="{{ $cel }}">
        @if($drugie && $opakowanie !== null)
            <input type="hidden" name="opakowanie_id" value="{{ $opakowanie->opakowanieId }}">
        @elseif(! $drugie && $maDwa && $opakowanie !== null)
            <input type="hidden" name="odcisk" value="{{ $opakowanie->odcisk() }}">
        @endif
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

        @if($dataZPrzycisku !== null && $przyciskZPrzed !== null)
            <p class="mt-4" data-data-z-przycisku><strong>Wybrano „{{ $przyciskZPrzed }}”.</strong> Data jest wpisana w listach niżej. Zaznacz jeszcze, jaki to termin, i naciśnij „Zapisz”.</p>
        @endif

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

        <x-field name="ilosc" label="Ilość" :value="$poBledzie ? old('ilosc') : $opakowanie?->quantity_note" autocomplete="off"
                 help="Nie trzeba. Wpisz słowami, na przykład „pół kostki” albo „1 litr”. Najwyżej 40 znaków." />

        <div class="field">
            <label class="choice" for="f-mrozone">
                <input id="f-mrozone" type="checkbox" name="mrozone" value="1" @checked($mrozone)>
                <span>
                    <span class="choice-label">Mam to w zamrażarce</span>
                    <span class="choice-help">Produkt z zamrażarki nie trafia do sekcji „Zużyj w pierwszej kolejności”. Wpisany termin zostaje.@if($oznaczone) Dotyczy tylko tego opakowania.@endif</span>
                </span>
            </label>
        </div>

        <p class="meta">
            Termin to Twoja notatka z opakowania. Kuking nie ocenia, czy produkt nadaje się do jedzenia.
            Sprawdź go przed użyciem.
        </p>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ $nowe ? 'Dodaj drugie opakowanie' : 'Zapisz' }}</button>
            @if($opakowanie?->expires_on !== null)
                <button class="btn btn-secondary" type="submit" name="wyczysc" value="1">Wyczyść termin</button>
            @endif
            <a class="btn btn-quiet" href="{{ route('pantry.index') }}">Wróć do listy</a>
        </div>
    </form>

    @if(! $oznaczone)
        {{-- Drugie opakowanie powstaje wyłącznie z tego jawnego przycisku (#2568). Zwykłe
             dodanie tej samej nazwy do listy nadal nie tworzy niczego nowego. --}}
        <section class="sekcja-strony mt-8" aria-labelledby="drugie-opakowanie" data-drugie-opakowanie>
            <h2 class="mt-0" id="drugie-opakowanie">Masz drugie opakowanie tego produktu?</h2>
            <p>Jeśli drugie opakowanie ma inny termin albo jest w zamrażarce, dodaj je osobno. Każde będzie miało własny termin i ilość. Pierwsze zostaje bez zmian.</p>
            <a class="btn btn-secondary" href="{{ route('pantry.edit', ['pantryItem' => $produkt, 'opakowanie' => 'drugie']) }}"
               aria-label="Dodaj drugie opakowanie: {{ $produkt->name }}">Dodaj drugie opakowanie</a>
        </section>
    @endif
</x-layout>
