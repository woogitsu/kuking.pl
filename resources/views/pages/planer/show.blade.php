{{--
    Planer tygodnia (#27, D-310). Prywatny, `noindex`.

    Bez przeciągania — każda akcja to zwykły formularz z przyciskiem, więc
    ekran działa bez skryptu i jedną ręką. Każdy dzień ma własny formularz
    „Dopisz coś własnego”; `:wiersz` = data dnia, żeby błąd i wpisany tekst
    wróciły tylko do tego dnia, z którego przyszły (issue #243).

    Przepis, którego właściciel planu już nie widzi, stoi bez tytułu i bez
    linku („Przepis jest już niedostępny.”) — plan nie jest furtką do treści
    (`PlanerTygodnia`).
--}}
@use('App\Domain\Planer\PlanerTygodnia')
<x-layout title="Planer tygodnia" :noindex="true">
    <h1>Plan na tydzień</h1>
    <p class="mb-5">{{ PlanerTygodnia::zakresTygodnia($poniedzialek) }}. Ten plan widzisz tylko Ty.</p>

    @php
        // Cele linków z podsumowania błędów. Domyślne `#f-day` / `#f-q` nie
        // istnieje na tej stronie: pole dnia jest ukryte, a pole wyszukiwania
        // ma id `q-{data}`. Błąd z wyników wyszukiwania (POST `z_planera`)
        // prowadzi do pola wyszukiwania tego dnia, z którego przyszedł; błąd
        // z „Dopisz coś własnego” — do pola `label` tego dnia (id z `_wiersz`).
        // Dzień spoza tygodnia na ekranie (np. poza zakresem planera) albo
        // pełny dzień bez pola — pierwszy dzień, który pole ma.
        $dniZPolemSzukania = collect($dni)
            ->filter(fn ($d) => count($d['pozycje']) < $wpisowNaDzien)
            ->keys()
            ->map(fn ($k) => (string) $k)
            ->all();
        $celeBledow = [];
        if ($errors->any()) {
            if (old('z_planera')) {
                $dzienBledu = old('day');
                if (! is_string($dzienBledu) || ! in_array($dzienBledu, $dniZPolemSzukania, true)) {
                    $dzienBledu = in_array((string) $szukanyDzien, $dniZPolemSzukania, true)
                        ? $szukanyDzien
                        : ($dniZPolemSzukania[0] ?? null);
                }
                $cel = $dzienBledu !== null ? 'q-'.$dzienBledu : null;
            } else {
                $wierszBledu = \App\Support\WierszFormularza::aktywnyWiersz();
                $cel = $wierszBledu !== null && in_array($wierszBledu, $dniZPolemSzukania, true)
                    ? 'f-label-'.$wierszBledu
                    : (isset($dniZPolemSzukania[0]) ? 'q-'.$dniZPolemSzukania[0] : null);
            }
            if ($cel !== null) {
                $celeBledow = ['day' => $cel, 'q' => $cel, 'recipe_id' => $cel, 'label' => $cel];
            }
        }
    @endphp
    <x-error-summary :field-ids="$celeBledow" />

    <nav class="planer-nawigacja mb-5" aria-label="Wybór tygodnia">
        <a class="btn btn-secondary" href="{{ route('planer.show', ['tydzien' => $poniedzialek->subDays(7)->toDateString()]) }}">Poprzedni tydzień</a>
        @unless($tenTydzien)
            <a class="btn btn-secondary" href="{{ route('planer.show') }}">Wróć do tego tygodnia</a>
        @endunless
        <a class="btn btn-secondary" href="{{ route('planer.show', ['tydzien' => $poniedzialek->addDays(7)->toDateString()]) }}">Następny tydzień</a>
        <a class="btn btn-secondary" href="{{ route('shopping.index') }}">Lista zakupów</a>
    </nav>

    @if($poprzedniMaPozycje)
        <form class="card mb-5" method="POST" action="{{ route('planer.copy') }}">
            @csrf
            <input type="hidden" name="tydzien" value="{{ $poniedzialek->toDateString() }}">
            <p class="mt-0" id="opis-kopii">Dopiszemy pozycje z poprzedniego tygodnia do tych samych dni tego tygodnia. To, co już jest w planie, zostaje i nie powtórzy się.</p>
            <button class="btn btn-secondary" type="submit" aria-describedby="opis-kopii">Skopiuj poprzedni tydzień</button>
        </form>
    @endif

    <p class="meta meta-samodzielne mb-5">Przy każdym dniu wyszukasz przepis i dodasz go do planu. Możesz też dopisać coś własnego, np. „obiad u mamy”. Przepis dodasz również z jego strony — przyciskiem „Dodaj do planera”. Przy przepisie w planie „Dodaj składniki” kopiuje jego składniki na listę zakupów.</p>

    <div class="planer-dni">
        @foreach($dni as $dataDnia => $dzien)
            @php
                $naglowekId = 'dzien-'.$dataDnia;
            @endphp
            <section class="card" aria-labelledby="{{ $naglowekId }}">
                <h2 class="mt-0 planer-dzien-naglowek" id="{{ $naglowekId }}">
                    {{ \Illuminate\Support\Str::ucfirst(PlanerTygodnia::nazwaDnia($dzien['dzien'])) }}
                    @if($dataDnia === $dzis)
                        <span class="planer-dzis">dziś</span>
                    @endif
                </h2>

                @if($dzien['pozycje'] === [])
                    <p class="meta meta-samodzielne">Nic jeszcze nie zaplanowane.</p>
                @else
                    <ul class="planer-pozycje">
                        @foreach($dzien['pozycje'] as $pozycja)
                            @php
                                $wpis = $pozycja['wpis'];
                                $nazwa = match ($pozycja['stan']) {
                                    PlanerTygodnia::STAN_PRZEPIS => $pozycja['przepis']->title,
                                    PlanerTygodnia::STAN_WLASNY => $wpis->label,
                                    PlanerTygodnia::STAN_NIEDOSTEPNY => 'przepis niedostępny',
                                    default => 'przepis usunięty',
                                };
                            @endphp
                            <li class="planer-pozycja">
                                <span class="planer-pozycja-tresc">
                                    @if($pozycja['stan'] === PlanerTygodnia::STAN_PRZEPIS)
                                        <a href="{{ route('recipes.show', $pozycja['przepis']->slug) }}">{{ $pozycja['przepis']->title }}</a>
                                    @elseif($pozycja['stan'] === PlanerTygodnia::STAN_WLASNY)
                                        {{ $wpis->label }}
                                    @elseif($pozycja['stan'] === PlanerTygodnia::STAN_NIEDOSTEPNY)
                                        <span class="meta">Przepis jest już niedostępny.</span>
                                    @else
                                        <span class="meta">Przepis został usunięty.</span>
                                    @endif
                                </span>
                                <span class="planer-nawigacja">
                                    @if($pozycja['stan'] === PlanerTygodnia::STAN_PRZEPIS)
                                        {{-- Lista zakupów (#27, etap 2, D-333): linie składników tego przepisu. --}}
                                        <form method="POST" action="{{ route('shopping.recipe.store', $pozycja['przepis']->slug) }}">
                                            @csrf
                                            <input type="hidden" name="z_planera" value="1">
                                            <button class="btn btn-secondary" type="submit">Dodaj składniki<span class="visually-hidden"> do listy zakupów: {{ $nazwa }}</span></button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('planer.destroy', $wpis) }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-secondary" type="submit">Usuń z planu<span class="visually-hidden">: {{ $nazwa }}</span></button>
                                    </form>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if(count($dzien['pozycje']) < $wpisowNaDzien)
                    @php
                        $aktywny = $szukanyDzien === $dataDnia;
                        $szukajId = 'szukaj-'.$dataDnia;
                        // Błąd wyszukiwania (GET) należy do dnia z adresu. Błąd
                        // dodania z wyników (POST) — do dnia, z którego ten POST
                        // przyszedł (`old('day')` przy `z_planera`), a NIE do dnia
                        // z adresu strony, z której człowiek wysłał formularz.
                        // Błąd „Dopisz coś własnego” pokazuje samo pole `label`
                        // (`_wiersz`), więc tu go nie powielamy.
                        $zWynikow = old('z_planera') && old('day') === $dataDnia;
                        $bladDnia = ($aktywny ? $bladFrazy : null)
                            ?? ($zWynikow ? ($errors->first('day') ?: $errors->first('label') ?: $errors->first('q')) : null)
                            ?: null;
                    @endphp
                    <div class="planer-szukaj mt-4" id="{{ $szukajId }}" tabindex="-1" role="group" aria-labelledby="{{ $szukajId }}-tytul">
                        <h3 class="mt-0" id="{{ $szukajId }}-tytul">Dodaj przepis do tego dnia</h3>
                        <form class="planer-dopisz" method="GET" action="{{ route('planer.show') }}#{{ $szukajId }}">
                            <input type="hidden" name="tydzien" value="{{ $poniedzialek->toDateString() }}">
                            <input type="hidden" name="dzien" value="{{ $dataDnia }}">
                            <div class="field @if($bladDnia) has-error @endif">
                                <label for="q-{{ $dataDnia }}">Nazwa przepisu</label>
                                <input class="field-input" id="q-{{ $dataDnia }}" type="search" name="q" value="{{ $aktywny ? $fraza : '' }}" autocomplete="off"
                                       @if($bladDnia) aria-invalid="true" aria-describedby="q-{{ $dataDnia }}-blad" @endif>
                                @if($bladDnia)
                                    <span class="field-error" id="q-{{ $dataDnia }}-blad">{{ $bladDnia }}</span>
                                @endif
                            </div>
                            <button class="btn btn-secondary" type="submit">Szukaj przepisu</button>
                        </form>
                        @if($aktywny && $fraza !== '' && ! $bladFrazy)
                            @if($wyniki->isEmpty())
                                <p class="meta meta-samodzielne">Nic nie znaleźliśmy dla „{{ $fraza }}”. Spróbuj krótszego słowa, np. samej nazwy dania.</p>
                            @else
                                <ul class="planer-pozycje mt-3" aria-label="Znalezione przepisy">
                                    @foreach($wyniki as $znaleziony)
                                        <li class="planer-pozycja">
                                            <span class="planer-pozycja-tresc">{{ $znaleziony->title }}</span>
                                            <form method="POST" action="{{ route('planer.store') }}">
                                                @csrf
                                                <input type="hidden" name="day" value="{{ $dataDnia }}">
                                                <input type="hidden" name="recipe_id" value="{{ $znaleziony->getKey() }}">
                                                <input type="hidden" name="z_planera" value="1">
                                                <input type="hidden" name="q" value="{{ $fraza }}">
                                                <button class="btn btn-secondary" type="submit">Dodaj do planu<span class="visually-hidden">: {{ $znaleziony->title }}</span></button>
                                            </form>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        @endif
                    </div>

                    <form class="planer-dopisz mt-4" method="POST" action="{{ route('planer.store') }}">
                        @csrf
                        <input type="hidden" name="_wiersz" value="{{ $dataDnia }}">
                        <input type="hidden" name="day" value="{{ $dataDnia }}">
                        <x-field name="label" label="Dopisz coś własnego" :wiersz="$dataDnia" :bez-oznaczenia="true" />
                        <button class="btn btn-secondary" type="submit">Dopisz</button>
                    </form>
                @else
                    <p class="meta mt-4">Ten dzień ma komplet: {{ $wpisowNaDzien }} pozycji. Usuń którąś, żeby dopisać nową.</p>
                @endif
            </section>
        @endforeach
    </div>
</x-layout>
