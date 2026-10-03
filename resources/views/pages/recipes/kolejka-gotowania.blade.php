@php
    /*
     * Kolejka kilku przepisów (#2379, D-333). Cały stan kolejki jest w adresie
     * (`?p=slug:krok,slug:krok&a=slug`), więc każdy odnośnik niżej to zwykły
     * link do tego samego ekranu z inną listą — działa bez JavaScriptu.
     * Skrypt (`kolejka-gotowania.js`) pamięta kolejkę w przeglądarce i liczy
     * minutniki; bez niego widać potrawy, kroki i linki do trybu pojedynczego.
     */
    $kroki = collect($pozycje)->mapWithKeys(fn (array $p): array => [$p['recipe']->slug => $p['krok']])->all();
    $adresKolejki = function (array $mapaKrokow, ?string $aktywny, ?string $usuniety = null) use ($kroki): string {
        $lista = collect($mapaKrokow)->map(fn (int $krok, string $slug): string => $slug.':'.$krok)->implode(',');

        return route('kolejka-gotowania', array_filter(['p' => $lista, 'a' => $aktywny, 'u' => $usuniety], fn ($wartosc) => $wartosc !== null));
    };
    $slugi = array_keys($kroki);
    $liczba = count($slugi);
    $aktywna = collect($pozycje)->first(fn (array $p): bool => $p['recipe']->slug === $aktywnySlug);
@endphp
<x-layout title="Gotuję kilka potraw naraz" :noindex="true">
    <div class="stack max-w-[38rem] mx-auto" data-kolejka-ekran
         data-kolejka-z-adresu="{{ $zAdresu ? '1' : '0' }}"
         data-kolejka-adres="{{ route('kolejka-gotowania') }}"
         data-kolejka-wygasla="{{ $wygasla ? '1' : '0' }}"
         data-kolejka-aktywna="{{ $aktywnySlug }}"
         data-kolejka-pominiete="{{ json_encode([...$pominiete, ...($usuniete !== '' ? [$usuniete] : [])], JSON_THROW_ON_ERROR) }}"
         data-kolejka-dane="{{ json_encode(array_map(fn (array $p): array => [
             'slug' => $p['recipe']->slug,
             'krok' => $p['krok'],
             'tytul' => $p['recipe']->title,
             'tozsamoscKrokow' => $p['recipe']->steps->map(fn ($step): array => [
                 'id' => (string) $step->getKey(),
                 'fingerprint' => $step->timerFingerprint(),
             ])->all(),
         ], $pozycje), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}">
        <h1 class="m-0">Gotuję kilka potraw naraz</h1>

        {{-- Alarmy minutników (role=alert). Wypełnia je skrypt; bez niego puste i ukryte. --}}
        <div class="stack" data-kolejka-alarmy hidden></div>

        @php
            $uwagi = [];
            if ($wygasla) {
                $uwagi[] = 'Kolejka wygasła po 24 godzinach od ostatniej zmiany i została wyczyszczona. Dodaj przepisy jeszcze raz.';
            }
            if ($niedostepne > 0) {
                $uwagi[] = ($niedostepne === 1 ? 'Jeden przepis z kolejki jest już niedostępny' : $niedostepne.' przepisy z kolejki są już niedostępne')
                    .' (został usunięty, ukryty albo wrócił do szkicu), więc wypadł z kolejki. Reszta zostaje.';
            }
            if ($bezKrokow > 0) {
                $uwagi[] = 'Przepis bez opisanych kroków nie nadaje się do gotowania krok po kroku, więc wypadł z kolejki.';
            }
            if ($przekroczonoLimit) {
                $uwagi[] = 'W kolejce mieszczą się najwyżej '.$limit.' przepisy. Pozostałe z adresu pominęliśmy.';
            }
        @endphp
        @foreach($uwagi as $uwaga)
            <div class="flash-ramka flash-ramka-informacja" data-rodzaj-komunikatu="informacja" data-kolejka-uwaga>
                <p class="flash-etykieta">{{ \App\Support\Komunikat::ETYKIETY[\App\Support\Komunikat::INFORMACJA] }}</p>
                <p class="flash">{{ $uwaga }}</p>
            </div>
        @endforeach

        @if($aktywna === null)
            <section class="stack" aria-label="Pusta kolejka">
                <p class="m-0" data-kolejka-pusta>Kolejka jest pusta. Otwórz przepis i wybierz „Dodaj do kolejki gotowania”. Zmieści się {{ $limit }} przepisy, a kolejka znika po 24 godzinach.</p>
                <p class="m-0 text-ink-muted" data-kolejka-bez-js>Kolejka jest zapamiętywana w tej przeglądarce i do jej prowadzenia potrzebny jest włączony JavaScript. Bez niego gotuj przepisy po kolei, każdy w trybie „Gotuję” na swojej stronie.</p>
                <a class="btn btn-primary" href="{{ route('search') }}">Znajdź przepis</a>
            </section>
        @else
            @php
                $slugAktywny = $aktywna['recipe']->slug;
                $indeks = array_search($slugAktywny, $slugi, true);
                $krokAktywny = $aktywna['krok'];
                $totalAktywny = $aktywna['total'];
                $krokModel = $aktywna['recipe']->steps->get($krokAktywny - 1);
                $etykietaMinutnika = $krokModel->timerLabel(afterNa: true);

                $zKrokiem = fn (string $slug, int $nowy): array => [...$kroki, $slug => $nowy];
                $zamien = function (int $a, int $b) use ($slugi, $kroki): array {
                    $kolejnosc = $slugi;
                    [$kolejnosc[$a], $kolejnosc[$b]] = [$kolejnosc[$b], $kolejnosc[$a]];

                    return collect($kolejnosc)->mapWithKeys(fn (string $s): array => [$s => $kroki[$s]])->all();
                };
                $bezAktywnej = array_diff_key($kroki, [$slugAktywny => true]);
            @endphp

            {{-- Przełącznik potraw: nazwane przyciski-odnośniki, bez hover i swipe. --}}
            <nav aria-label="Potrawy w kolejce">
                <ul class="kolejka-potrawy">
                    @foreach($pozycje as $p)
                        @php($czynna = $p['recipe']->slug === $slugAktywny)
                        <li>
                            <a class="btn {{ $czynna ? 'btn-primary' : 'btn-secondary' }} kolejka-potrawa"
                               href="{{ $adresKolejki($kroki, $p['recipe']->slug) }}"
                               @if($czynna) aria-current="page" @endif>
                                <span class="kolejka-potrawa-nazwa">{{ $p['recipe']->title }}</span>
                                <span class="kolejka-potrawa-krok">krok {{ $p['krok'] }} z {{ $p['total'] }}@if($czynna) — teraz widzisz tę potrawę @endif</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            {{-- Wspólna lista aktywnych minutników wszystkich potraw. Wypełnia ją skrypt. --}}
            <section class="stack" data-kolejka-minutniki aria-label="Działające minutniki" hidden>
                <h2 class="m-0">Działające minutniki</h2>
                <ul class="kolejka-minutniki-lista" data-kolejka-minutniki-lista></ul>
            </section>

            <section class="cook-step" aria-label="Bieżący krok: {{ $aktywna['recipe']->title }}">
                <h2 class="m-0 kolejka-tytul">{{ $aktywna['recipe']->title }}</h2>
                <p class="cook-step-numer" aria-live="polite">Krok {{ $krokAktywny }} z {{ $totalAktywny }}</p>
                @php($etapKroku = \App\Domain\Recipes\EtapyPrzygotowania::nazwaDlaKroku($aktywna['recipe']->steps, $krokAktywny - 1))
                @if($etapKroku !== null)
                    <p class="cook-step-etap">Etap: {{ $etapKroku }}</p>
                @endif
                <p class="cook-step-tekst">{{ $krokModel->instruction }}</p>

                @if($krokModel->media)
                    <div class="cook-step-zdjecie" data-kolejka-zdjecie-kroku>
                        {{-- Ta sama bramka wariantu i uprawnień co w trybie pojedynczym;
                             tylko zdjęcie bieżącego kroku aktywnej potrawy. --}}
                        <x-photo :media="$krokModel->media" variant="feed" class="post-photo"
                                 :leniwie="false"
                                 :alt="$krokModel->media->alt_text ?: 'Zdjęcie do kroku '.$krokAktywny"
                                 tresc="przepis"
                                 :wymien-url="auth()->user()?->isActive() && auth()->user()->can('update', $aktywna['recipe']) ? route('recipes.edit', $slugAktywny).'#f-steps-'.($krokAktywny - 1).'-photo' : null" />
                    </div>
                @endif

                @if($etykietaMinutnika)
                    <div class="cook-timer" data-kolejka-minutnik
                         data-slug="{{ $slugAktywny }}" data-krok="{{ $krokAktywny }}"
                         data-sekundy="{{ $krokModel->timer_seconds }}" data-etykieta="{{ $etykietaMinutnika }}"
                         data-step-id="{{ $krokModel->getKey() }}" data-fingerprint="{{ $krokModel->timerFingerprint() }}">
                        {{-- Baza bez JS: samo zdanie. Przycisk odkrywa dopiero skrypt (D-053). --}}
                        <p>Ustaw sobie kuchenny minutnik na {{ $etykietaMinutnika }}.</p>
                        <button type="button" class="btn btn-secondary btn-cook" data-kolejka-minutnik-start hidden>
                            Uruchom minutnik: {{ $aktywna['recipe']->title }}
                        </button>
                        <p class="visually-hidden" aria-live="assertive" data-kolejka-minutnik-komunikat></p>
                    </div>
                @endif
            </section>

            <nav class="cook-nav" aria-label="Nawigacja krokami: {{ $aktywna['recipe']->title }}">
                @if($krokAktywny < $totalAktywny)
                    <a class="btn btn-primary btn-cook" href="{{ $adresKolejki($zKrokiem($slugAktywny, $krokAktywny + 1), $slugAktywny) }}">
                        Następny krok <span aria-hidden="true">→</span>
                    </a>
                @endif
                @if($krokAktywny > 1)
                    <a class="btn btn-secondary btn-cook" href="{{ $adresKolejki($zKrokiem($slugAktywny, $krokAktywny - 1), $slugAktywny) }}">
                        <span aria-hidden="true">←</span> Poprzedni krok
                    </a>
                @endif
            </nav>

            {{--
                PODGLĄD SKŁADNIKÓW (#2469). Tylko aktywna potrawa, domyślnie zwinięty,
                zwykłe `details` (bez JavaScriptu). Ilości są ORYGINALNE, dla liczby
                porcji podanej przez autora — bez mnożenia i bez checklisty. Układ
                grup, uwagi i zamienniki jak w trybie gotowania; dopisek „do smaku”
                według D-232. Składników nie kopiujemy do `data-kolejka-dane` ani do
                pamięci przeglądarki.
            --}}
            @php($skladnikiAktywnej = $aktywna['recipe']->ingredients)
            <details class="cook-ingredients" data-kolejka-skladniki>
                <summary>Składniki: {{ $aktywna['recipe']->title }} ({{ $skladnikiAktywnej->count() }})</summary>
                @php($porcjeAutora = \App\Domain\Recipes\Porcje\WyborPorcji::dla($aktywna['recipe'], null)->zPrzepisu)
                @if($porcjeAutora !== null)
                    <p class="meta">Ilości podał autor {{ \App\Domain\Recipes\Porcje\WyborPorcji::naIle($porcjeAutora) }}. W tym widoku nie są przeliczane.</p>
                @else
                    <p class="meta">Ilości podał autor. W tym widoku nie są przeliczane.</p>
                @endif
                @if($skladnikiAktywnej->isEmpty())
                    <p class="meta">Autor jeszcze nie dodał składników.</p>
                @else
                    @foreach(\App\Domain\Recipes\GrupySkladnikow::ulozyc($skladnikiAktywnej) as $grupaSkladnikow)
                        @if($grupaSkladnikow['nazwa'] !== null)
                            <h3 class="naglowek-grupy">{{ $grupaSkladnikow['nazwa'] }}</h3>
                        @endif
                        <ul class="ingredient-list">
                            @foreach($grupaSkladnikow['skladniki'] as $ingredient)
                                <li data-kolejka-skladnik>
                                    {{ $ingredient->ingredient_text }}
                                    @if($ingredient->no_amount && ! str_contains(mb_strtolower($ingredient->ingredient_text), 'do smaku'))
                                        <span class="meta"> — do smaku</span>
                                    @endif
                                    @if($ingredient->note)<span class="meta"> — {{ $ingredient->note }}</span>@endif
                                    @if($ingredient->substitutes)<span class="skladnik-zamiennik">Zamiast tego: {{ $ingredient->substitutes }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    @endforeach
                @endif
            </details>

            @if($krokAktywny === $totalAktywny)
                <section class="cook-finish">
                    <h2 class="mt-0">To już ostatni krok: {{ $aktywna['recipe']->title }}.</h2>
                    @can('cook', $aktywna['recipe'])
                        <p>Ta potrawa gotowa? Dodaj zdjęcie efektu. Pozostałe potrawy zostają w kolejce.</p>
                        <a class="btn btn-primary btn-cook" href="{{ route('cooked.create', $slugAktywny) }}">{{ \App\Support\Forma::dla(auth()->user(), 'Ugotowałam', 'Ugotowałem', 'Ugotowałem') }}</a>
                    @else
                        @guest
                            <p>Załóż konto, żeby dać znać autorowi, że Ci wyszło.</p>
                            <a class="btn btn-primary btn-cook" href="{{ route('register', ['cook_recipe' => $aktywna['recipe']->getKey()]) }}">Załóż konto</a>
                        @endguest
                    @endcan
                </section>
            @endif

            <section class="stack" aria-label="Kolejność i zawartość kolejki">
                <h2 class="m-0">Kolejka ({{ $liczba }} z {{ $limit }})</h2>
                <div class="kolejka-akcje">
                    @if($indeks > 0)
                        <a class="btn btn-secondary" href="{{ $adresKolejki($zamien($indeks, $indeks - 1), $slugAktywny) }}">Przesuń „{{ $aktywna['recipe']->title }}” wyżej</a>
                    @endif
                    @if($indeks < $liczba - 1)
                        <a class="btn btn-secondary" href="{{ $adresKolejki($zamien($indeks, $indeks + 1), $slugAktywny) }}">Przesuń „{{ $aktywna['recipe']->title }}” niżej</a>
                    @endif
                    <a class="btn btn-secondary" href="{{ $adresKolejki($bezAktywnej, null, $slugAktywny) }}">Usuń „{{ $aktywna['recipe']->title }}” z kolejki</a>
                    <a class="btn btn-secondary" href="{{ route('cooking.show', ['recipe' => $slugAktywny, 'krok' => $krokAktywny]) }}">Gotuj tylko „{{ $aktywna['recipe']->title }}” (tryb jednego przepisu)</a>
                    <a class="btn btn-secondary" href="{{ route('recipes.show', $slugAktywny) }}">Zobacz cały przepis</a>
                </div>

                {{-- Czyszczenie: tylko ze skryptem (kolejka jest w przeglądarce), z potwierdzeniem w dwóch przyciskach. --}}
                <div class="stack" data-kolejka-czyszczenie hidden>
                    <button type="button" class="btn btn-secondary" data-kolejka-wyczysc>Wyczyść kolejkę</button>
                    <div class="stack" data-kolejka-wyczysc-potwierdz hidden>
                        <p class="m-0">Wyczyścić całą kolejkę? Zniknie lista potraw i działające minutniki. Same przepisy zostają nietknięte.</p>
                        <button type="button" class="btn btn-primary" data-kolejka-wyczysc-tak>Tak, wyczyść kolejkę</button>
                        <button type="button" class="btn btn-secondary" data-kolejka-wyczysc-nie>Nie, zostaw kolejkę</button>
                    </div>
                </div>
                <p class="m-0 text-ink-muted">Kolejka jest zapamiętana tylko w tej przeglądarce i znika po 24 godzinach od ostatniej zmiany. Zamknięcie karty kończy działające minutniki, a kolejka zostaje.</p>
            </section>
        @endif
    </div>
</x-layout>
