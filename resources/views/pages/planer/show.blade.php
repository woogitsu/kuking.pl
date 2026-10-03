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
            // Dopisek przy przepisie (#2549): błąd prowadzi do pola TEJ pozycji.
            if ($errors->has('note') && \App\Support\WierszFormularza::aktywnyWiersz() !== null) {
                $celeBledow['note'] = 'f-note-'.\App\Support\WierszFormularza::aktywnyWiersz();
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
        {{-- Kartka papierowa wybranego tygodnia (#2498): zachowuje `tydzien`. --}}
        <a class="btn btn-secondary" href="{{ route('planer.print', ['tydzien' => $poniedzialek->toDateString()]) }}" data-rola="wydrukuj-tydzien">Wydrukuj ten tydzień</a>
        <a class="btn btn-secondary" href="{{ route('planer.calendar', ['tydzien' => $poniedzialek->toDateString()]) }}">Plan do kalendarza (plik)</a>
    </nav>

    {{-- Wybór tygodnia po dacie (#2513): zwykły GET bez skryptu. Zły albo pusty
         `tydzien` daje bieżący tydzień (PlanerTygodnia::poniedzialek). Jak
         przyciski wyżej, nie przenosi wyszukiwania dnia (`dzien`, `q`). --}}
    <form class="planer-dopisz mb-5" method="GET" action="{{ route('planer.show') }}" novalidate>
        <div class="field">
            <label for="f-tydzien">Pokaż tydzień z dniem</label>
            <input class="field-input" id="f-tydzien" type="date" name="tydzien" value="{{ $poniedzialek->toDateString() }}">
        </div>
        <button class="btn btn-secondary" type="submit">Pokaż tydzień</button>
    </form>
    <section class="card mb-5" aria-labelledby="szukaj-w-planach-tytul">
        <h2 class="mt-0" id="szukaj-w-planach-tytul">Szukaj w moich planach</h2>
        <p class="meta meta-samodzielne">Pamiętasz, co jest w planie, ale nie kiedy? Wpisz kawałek tekstu, np. „obiad u mamy”, a pokażemy dni z Twojego planu. To inne szukanie niż „Szukaj przepisu” przy dniu, które dodaje przepis do planu.</p>
        <form class="planer-dopisz" method="GET" action="{{ route('planer.show') }}#wyniki-w-planach">
            @if($bladWPlanach)
                {{-- Błąd GET ma własny klucz i cel; nie zastępuje błędów innych formularzy z sesji. --}}
                @include('components.error-summary', [
                    'errors' => new \Illuminate\Support\MessageBag(['szukaj_w_planach' => $bladWPlanach]),
                    'fieldIds' => ['szukaj_w_planach' => 'szukaj-w-planach'],
                ])
            @endif
            <div class="field @if($bladWPlanach) has-error @endif">
                <label for="szukaj-w-planach">Czego szukasz w planie?</label>
                <input class="field-input" id="szukaj-w-planach" type="search" name="szukaj_w_planach" value="{{ $wPlanach }}" autocomplete="off"
                       @if($bladWPlanach) aria-invalid="true" aria-describedby="szukaj-w-planach-blad" @endif>
                @if($bladWPlanach)
                    <span class="field-error" id="szukaj-w-planach-blad">{{ $bladWPlanach }}</span>
                @endif
            </div>
            <button class="btn btn-secondary" type="submit">Szukaj w moich planach</button>
        </form>
        @if($wynikiWPlanach !== null)
            <div id="wyniki-w-planach" tabindex="-1">
                @if($wynikiWPlanach['wyniki'] === [])
                    <p class="meta meta-samodzielne">Nic nie znaleźliśmy w Twoich planach dla „{{ $wPlanach }}”. Spróbuj krótszego słowa, np. samej nazwy dania.</p>
                @else
                    <ul class="planer-pozycje mt-3" aria-label="Znalezione pozycje w Twoich planach">
                        @foreach($wynikiWPlanach['wyniki'] as $wynik)
                            @php
                                $dataWyniku = $wynik['wpis']->day;
                                $nazwaWyniku = $wynik['przepis']?->title ?? $wynik['wpis']->label;
                            @endphp
                            <li class="planer-pozycja">
                                <span class="planer-pozycja-tresc">
                                    <strong>{{ \Illuminate\Support\Str::ucfirst(PlanerTygodnia::nazwaDnia($dataWyniku)) }} {{ $dataWyniku->year }}</strong><br>
                                    {{ $nazwaWyniku }}
                                </span>
                                <a class="btn btn-secondary" href="{{ route('planer.show', ['tydzien' => $dataWyniku->toDateString()]) }}#dzien-{{ $dataWyniku->toDateString() }}">Pokaż ten tydzień<span class="visually-hidden">: {{ $nazwaWyniku }}, {{ PlanerTygodnia::nazwaDnia($dataWyniku) }} {{ $dataWyniku->year }}</span></a>
                            </li>
                        @endforeach
                    </ul>
                    @if($wynikiWPlanach['wiecej'])
                        <p class="meta meta-samodzielne">Pokazujemy {{ $limitWPlanach }} najnowszych pozycji, a jest ich więcej. Wpisz dokładniejszy tekst, żeby zawęzić wyniki.</p>
                    @endif
                @endif
            </div>
        @endif
    </section>

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
                    <span class="planer-dzien-data">{{ \Illuminate\Support\Str::ucfirst(PlanerTygodnia::nazwaDnia($dzien['dzien'])) }}</span>
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
                                $zrobione = $wpis->done_at !== null;
                                // Dopisek (#2549) tylko przy pozycji z przepisem; własny wpis ma swój tekst.
                                $maDopisek = $pozycja['stan'] !== PlanerTygodnia::STAN_WLASNY;
                                $bladDopisku = $maDopisek && \App\Support\WierszFormularza::jestAktywny($wpis->getKey()) && $errors->has('note');
                                // Planowane porcje (#2509) tylko przy dostępnym przepisie. `WyborPorcji`
                                // jest tym samym mechanizmem co `?porcje=` na stronie przepisu.
                                $maPorcje = $pozycja['stan'] === PlanerTygodnia::STAN_PRZEPIS;
                                $wyborPorcji = $maPorcje ? \App\Domain\Recipes\Porcje\WyborPorcji::dla($pozycja['przepis'], $wpis->planned_servings) : null;
                                $adresPrzepisu = $maPorcje
                                    ? route('recipes.show', array_filter([
                                        'recipe' => $pozycja['przepis']->slug,
                                        'porcje' => $wyborPorcji->dostepny() && ! $wyborPorcji->odrzucone && $wpis->planned_servings !== null
                                            ? $wyborPorcji->doAdresu($wyborPorcji->wybrane)
                                            : null,
                                    ], fn ($v) => $v !== null))
                                    : null;
                                $bladPorcji = $maPorcje && \App\Support\WierszFormularza::jestAktywny($wpis->getKey()) && $errors->has('porcje');
                            @endphp
                            <li class="planer-pozycja">
                                <span class="planer-pozycja-tresc">
                                    @if($pozycja['stan'] === PlanerTygodnia::STAN_PRZEPIS)
                                        <a href="{{ $adresPrzepisu }}">{{ $pozycja['przepis']->title }}</a>
                                    @elseif($pozycja['stan'] === PlanerTygodnia::STAN_WLASNY)
                                        {{ $wpis->label }}
                                    @elseif($pozycja['stan'] === PlanerTygodnia::STAN_NIEDOSTEPNY)
                                        <span class="meta">Przepis jest już niedostępny.</span>
                                    @else
                                        <span class="meta">Przepis został usunięty.</span>
                                    @endif
                                    @if($wpis->note !== null && $maDopisek)
                                        <span class="planer-dopisek-tekst">Dopisek: {{ $wpis->note }}</span>
                                    @endif
                                    @if($maPorcje && $wpis->planned_servings !== null)
                                        <span class="planer-dopisek-tekst">Planowane porcje: {{ \App\Domain\Recipes\Porcje\WyborPorcji::etykieta($wpis->planned_servings) }}@if(! $wyborPorcji->dostepny()) — ten przepis nie podaje liczby porcji, więc ilości zostają jak u autora @endif</span>
                                    @endif
                                    @if($zrobione)
                                        <span class="planer-zrobione">Zrobione</span>
                                    @endif
                                </span>
                                <span class="planer-nawigacja">
                                    {{-- Prywatne „Zrobione” (#2593): zwykły formularz, żądany stan + znacznik stanu z tej strony. --}}
                                    <form method="POST" action="{{ route('planer.done', $wpis) }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="zrobione" value="{{ $zrobione ? '0' : '1' }}">
                                        <input type="hidden" name="stan" value="{{ \App\Domain\Planer\Actions\OznaczPozycjePlanu::znacznik($wpis) }}">
                                        <button class="btn btn-secondary" type="submit">{{ $zrobione ? 'Cofnij oznaczenie' : 'Oznacz jako zrobione' }}<span class="visually-hidden">: {{ $nazwa }}</span></button>
                                    </form>
                                    {{-- Przeniesienie tej samej pozycji na inny dzień (#2447): osobny ekran z jednym polem daty. --}}
                                    <a class="btn btn-secondary" href="{{ route('planer.move.form', $wpis) }}">Przenieś na inny dzień<span class="visually-hidden">: {{ $nazwa }}, {{ PlanerTygodnia::nazwaDnia($dzien['dzien']) }}</span></a>
                                    @if($pozycja['stan'] === PlanerTygodnia::STAN_WLASNY)
                                        {{-- Poprawienie własnego tekstu bez usuwania pozycji (#2454): osobny ekran z jednym polem. --}}
                                        <a class="btn btn-secondary" href="{{ route('planer.text.edit', $wpis) }}">Zmień tekst<span class="visually-hidden">: {{ $nazwa }}, {{ PlanerTygodnia::nazwaDnia($dzien['dzien']) }}</span></a>
                                    @endif
                                    @if($pozycja['stan'] === PlanerTygodnia::STAN_PRZEPIS)
                                        {{-- Lista zakupów (#27, etap 2, D-333): linie składników tego przepisu. --}}
                                        <form method="POST" action="{{ route('shopping.recipe.store', $pozycja['przepis']->slug) }}">
                                            @csrf
                                            <input type="hidden" name="z_planera" value="1">
                                            <x-zakupy-wybor-listy :wiersz="'plan-'.$wpis->getKey()" />
                                            <button class="btn btn-secondary" type="submit">Dodaj składniki<span class="visually-hidden"> do listy zakupów: {{ $nazwa }}</span></button>
                                        </form>
                                        {{-- Tylko wybrane linie (#2462). --}}
                                        <a class="btn btn-secondary" href="{{ route('shopping.recipe.pick', ['recipe' => $pozycja['przepis'], 'z_planera' => 1]) }}">Wybierz składniki<span class="visually-hidden"> do zakupów: {{ $nazwa }}</span></a>
                                    @endif
                                </span>
                                @if($maDopisek)
                                    {{-- Prywatny dopisek (#2549): zwykły formularz; wpisany tekst wraca po błędzie, pole ma widoczną etykietę. --}}
                                    <details class="planer-dopisek" @if($bladDopisku) open @endif>
                                        <summary class="btn btn-secondary">{{ $wpis->note === null ? 'Dodaj dopisek' : 'Zmień dopisek' }}<span class="visually-hidden">: {{ $nazwa }}, {{ PlanerTygodnia::nazwaDnia($dzien['dzien']) }}</span></summary>
                                        <form class="planer-dopisz mt-3" method="POST" action="{{ route('planer.note', $wpis) }}" novalidate>
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="_wiersz" value="{{ $wpis->getKey() }}">
                                            <input type="hidden" name="stan" value="{{ \App\Domain\Planer\Actions\ZapiszDopisekPlanu::znacznik($wpis) }}">
                                            <x-field name="note" label="Dopisek (np. kolacja)" help="Najwyżej 80 znaków. Zostaw puste, żeby usunąć dopisek. Widzisz go tylko Ty." :value="$wpis->note" :wiersz="$wpis->getKey()" :bez-oznaczenia="true" />
                                            <button class="btn btn-secondary" type="submit">Zapisz dopisek</button>
                                        </form>
                                    </details>
                                @endif
                                @if($maPorcje && ($wyborPorcji->dostepny() || $wpis->planned_servings !== null))
                                    {{-- Planowane porcje (#2509): zwykły formularz; wpisana wartość wraca po błędzie. --}}
                                    <details class="planer-dopisek" @if($bladPorcji) open @endif>
                                        <summary class="btn btn-secondary">{{ $wpis->planned_servings === null ? 'Ustaw porcje' : 'Zmień porcje' }}<span class="visually-hidden">: {{ $nazwa }}, {{ PlanerTygodnia::nazwaDnia($dzien['dzien']) }}</span></summary>
                                        <form class="planer-dopisz mt-3" method="POST" action="{{ route('planer.servings', $wpis) }}" novalidate>
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="_wiersz" value="{{ $wpis->getKey() }}">
                                            <input type="hidden" name="stan" value="{{ \App\Domain\Planer\Actions\UstawPorcjePlanu::znacznik($wpis) }}">
                                            <x-field name="porcje" label="Na ile porcji w tym dniu? (np. 6)"
                                                     :help="($wyborPorcji->zPrzepisu !== null ? 'Przepis jest na '.\App\Domain\Recipes\Porcje\WyborPorcji::etykieta($wyborPorcji->zPrzepisu).'. ' : 'Ten przepis nie podaje liczby porcji. ').'Od '.\App\Domain\Recipes\Porcje\WyborPorcji::NAJMNIEJ.' do '.\App\Domain\Recipes\Porcje\WyborPorcji::NAJWIECEJ.'. Zostaw puste, żeby usunąć wybór — przepis otworzy się z ilościami autora. Widzisz to tylko Ty.'"
                                                     :value="$wpis->planned_servings === null ? '' : \App\Domain\Recipes\Porcje\WyborPorcji::doPola($wpis->planned_servings)"
                                                     inputmode="decimal" :wiersz="$wpis->getKey()" :bez-oznaczenia="true" />
                                            <button class="btn btn-secondary" type="submit">Zapisz porcje</button>
                                        </form>
                                    </details>
                                @endif
                                {{-- Usunięcie jest osobno: pierwszy dotyk rozwija pytanie, nie wysyła DELETE. --}}
                                <details class="confirm planer-potwierdzenie">
                                    <summary class="btn btn-danger confirm-summary">
                                        <span class="planer-usun-napis">Usuń z planu</span>
                                        <span class="planer-anuluj">Anuluj</span>
                                        <span class="visually-hidden">: {{ $nazwa }}, {{ PlanerTygodnia::nazwaDnia($dzien['dzien']) }}</span>
                                    </summary>
                                    <div class="confirm-body">
                                        <p class="confirm-question">Na pewno usunąć tę pozycję z planu na {{ PlanerTygodnia::naDzien($dzien['dzien']) }}? Tej operacji nie da się cofnąć.</p>
                                        <p class="confirm-question-nazwa"><strong>{{ $nazwa }}</strong></p>
                                        <form method="POST" action="{{ route('planer.destroy', $wpis) }}">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-danger" type="submit">Tak, usuń z planu</button>
                                        </form>
                                    </div>
                                </details>
                            </li>
                        @endforeach
                    </ul>
                    @php
                        // Do kolejki gotowania (#2450) trafiają tylko przepisy z krokami; wpisy własne,
                        // niedostępne i usunięte nie mają tu ani tytułu, ani pola wyboru.
                        $doKolejki = collect($dzien['pozycje'])
                            ->filter(fn (array $p): bool => $p['stan'] === PlanerTygodnia::STAN_PRZEPIS && isset($zKrokami[(string) $p['przepis']->getKey()]))
                            ->map(fn (array $p) => $p['przepis'])
                            ->unique(fn ($r) => $r->getKey())
                            ->values();
                    @endphp
                    @if($doKolejki->isNotEmpty())
                        {{-- Zestaw dnia do kolejki gotowania (#2450). Kolejka żyje w przeglądarce, więc
                             formularz odkrywa dopiero skrypt (D-053); bez niego zostają zwykłe linki. --}}
                        <form class="stack mt-4" data-planer-kolejka data-kolejka-adres="{{ route('kolejka-gotowania') }}" novalidate hidden>
                            <fieldset class="stack">
                                <legend><strong>Dodaj do kolejki gotowania</strong></legend>
                                <p class="meta meta-samodzielne m-0">Zaznacz potrawy, które chcesz gotować razem. Kolejka mieści najwyżej {{ \App\Http\Controllers\KolejkaGotowaniaController::LIMIT }} przepisy; to, co już w niej jest, zostaje.</p>
                                <div class="choice-grid">
                                    @foreach($doKolejki as $przepisDoKolejki)
                                        <label class="choice">
                                            <input type="checkbox" value="{{ $przepisDoKolejki->slug }}" data-planer-kolejka-pole>
                                            <span class="choice-label">{{ $przepisDoKolejki->title }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <p class="m-0" role="status" aria-live="polite" data-planer-kolejka-komunikat></p>
                            <button type="submit" class="btn btn-secondary">Dodaj zaznaczone do kolejki<span class="visually-hidden"> ({{ \App\Domain\Planer\PlanerTygodnia::nazwaDnia($dzien['dzien']) }})</span></button>
                        </form>
                        <div class="stack mt-4" data-planer-kolejka-bez-js>
                            <p class="meta meta-samodzielne m-0">Kolejka gotowania potrzebuje włączonego JavaScriptu i pamięci przeglądarki. Bez nich gotuj każdy przepis osobno:</p>
                            <ul class="list-none p-0 m-0 stack-tight">
                                @foreach($doKolejki as $przepisDoKolejki)
                                    <li><a class="btn btn-secondary" href="{{ route('cooking.show', $przepisDoKolejki->slug) }}">Gotuj: {{ $przepisDoKolejki->title }}</a></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    {{-- Powtórzenie zestawu dnia na inną datę (#2494): osobny ekran z podglądem. --}}
                    <p class="mt-3"><a class="btn btn-secondary" href="{{ route('planer.copyday', ['dzien' => $dataDnia]) }}">Skopiuj ten dzień<span class="visually-hidden">: {{ PlanerTygodnia::nazwaDnia($dzien['dzien']) }}</span></a></p>
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
                    <p class="meta meta-samodzielne mt-4">Ten dzień ma komplet: {{ $wpisowNaDzien }} pozycji. Usuń którąś, żeby dopisać nową.</p>
                @endif
            </section>
        @endforeach
    </div>
</x-layout>
