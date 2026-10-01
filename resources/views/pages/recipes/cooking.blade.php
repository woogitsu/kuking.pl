@php
    $poprzedniKrok = $krok > 1 ? $krok - 1 : null;
    $nastepnyKrok = $krok < $total ? $krok + 1 : null;
    $timerLabel = $aktualnyKrok->timerLabel(afterNa: true);
    $adresGotowania = fn (?int $numer = null): string => route('cooking.show', array_filter([
        'recipe' => $recipe->slug, 'krok' => $numer, 'porcje' => $parametrPorcji,
    ], fn ($wartosc) => $wartosc !== null));
    $adresPrzepisu = route('recipes.show', array_filter([
        'recipe' => $recipe->slug, 'porcje' => $parametrPorcji,
    ], fn ($wartosc) => $wartosc !== null));
@endphp
<x-layout
    :title="'Gotuję: '.$recipe->title"
    {{-- Ten widok to narzędzie DO gotowania konkretnej osoby, nie treść do
         znalezienia w wyszukiwarce — to samo źródło (przepis) już jest
         zaindeksowane pod /przepisy/{$recipe->slug}. Dwa indeksowalne
         adresy z tą samą treścią to duplikat, którego unikamy. --}}
    :noindex="true">

    <article class="stack max-w-[38rem] mx-auto">
        <div class="cook-topbar">
            {{-- `aria-live`, żeby czytnik ekranu ogłosił zmianę kroku po
                 kliknięciu „Poprzedni/Następny krok" — inaczej ta jedyna
                 informacja o postępie byłaby dostępna wyłącznie wzrokiem. --}}
            <p class="cook-progress" aria-live="polite">Krok {{ $krok }} z {{ $total }}</p>

            {{--
                Wyjście jest zawsze bezpieczne: postęp (zrobione kroki)
                siedzi w sesji, nie w tym adresie, więc „Zakończ" nigdy nic
                nie kasuje. Dlatego to zwykły link, bez potwierdzenia —
                potwierdzenie miałoby sens tylko, gdyby coś dało się stracić.
            --}}
            <a class="btn btn-secondary cook-exit" href="{{ $adresPrzepisu }}" data-minutniki-koniec>
                Zakończ gotowanie
            </a>
        </div>

        {{--
            Alarmy minutników z INNYCH kroków (issue #1301). Każdy krok to
            osobne przeładowanie strony, więc minutnik uruchomiony w kroku 1
            nie miał tu już żadnego kodu, który by go odliczał — po przejściu
            do kroku 2 nikt nie dzwonił. Skrypt wypełnia ten pas tylko wtedy,
            gdy taki minutnik się skończy. Bez JavaScriptu zostaje pusty
            i ukryty: minutnika w przeglądarce i tak wtedy nie ma.
        --}}
        <div class="cook-alarmy stack" data-alarmy-recipe="{{ $recipe->slug }}" data-alarmy-krok="{{ $krok }}" data-alarmy-adres="{{ $adresGotowania() }}" hidden></div>

        {{--
            ZMIANA POSTĘPU NA INNYM URZĄDZENIU (#2016). Pas jest ukryty i widoczny
            wyłącznie po odkryciu skryptem (`postep-gotowania.js`), gdy okresowe
            pytanie o rewizję pokaże, że stan zmienił się bez tej karty. Zawiera
            zwykły odnośnik, więc nie jest martwym przyciskiem (D-053); bez
            skryptu zostaje ukryty, a każde kliknięcie i tak pokazuje stan z konta.
        --}}
        @if($synchronizacja['wlaczona'])
            <div class="cook-sync-zmiana stack" role="status" hidden
                 data-postep-synchronizacja data-postep-rewizja="{{ $synchronizacja['rewizja'] }}"
                 data-postep-adres="{{ route('cooking.sync.postep', $recipe->slug) }}">
                <p class="m-0" data-postep-tekst>Postęp tego przepisu zmienił się na innym urządzeniu.</p>
                <a class="btn btn-secondary" href="{{ $adresGotowania($krok) }}">Pokaż aktualny postęp</a>
            </div>
        @endif

        <p class="meta m-0">{{ $recipe->title }}</p>

        {{--
            Składniki dostępne bez wychodzenia z trybu (issue #24) — natywny
            `<details>`, więc działa bez JavaScriptu i bez POST-a: samo
            rozwinięcie niczego nie zmienia, to nie jest stan warty zapisu.

            DOMYŚLNIE ZWINIĘTE: „jeden krok na ekranie" jest twardym
            wymaganiem tego widoku — rozwinięta lista składników na starcie
            odbierałaby krokowi bycie jedyną rzeczą, na którą patrzy oko
            zerkające znad garnka. Jedno dotknięcie, żeby sprawdzić „ile tej
            mąki", jest tańsze niż opuszczenie trybu — a to jest jedyna
            alternatywa, z którą to porównujemy.
        --}}
        {{--
            CHECKLISTA PRZYGOTOWANIA (issue #2069) — „mam już odmierzone”,
            nie „już dodane do garnka”. Odhaczenia są PAMIĘCIĄ TEJ KARTY
            (`sessionStorage`), tak samo jak przełącznik „Nie usypiaj
            ekranu” niżej: przeżywają zmianę kroku i odświeżenie, ale nie
            są danymi konta i nie wędrują na inne urządzenie (to osobne
            #2016). Kluczem jest ID przepisu i ID składnika — nie pozycja
            na liście, więc zmiana kolejności nie przeniesie odhaczenia na
            inny wiersz. Całość okablowuje `resources/js/skladniki-gotowania.js`.

            BEZ SKRYPTU nic tu się nie zmienia: pola, stan „Przygotowane”,
            licznik i „Wyczyść…” mają `hidden` i odkrywa je wyłącznie
            skrypt — lista zostaje zwykłą listą do czytania, bez kontrolki,
            która udawałaby, że coś zapamięta (D-053: żadnego martwego
            przycisku). Sekcja nadal startuje zwinięta.
        --}}
        {{--
            ETAP 2 SYNCHRONIZACJI (#2016): gdy osoba włączyła zapamiętywanie na
            koncie, checklista jest ZWYKŁYM FORMULARZEM (`zapiszSkladniki`) ze
            stanem z serwera, więc działa bez JavaScriptu; skrypt
            `skladniki-gotowania.js` jej wtedy nie rusza (brak `data-przygotowanie`).
            Bez synchronizacji zostaje jak dotąd — pamięć tej karty.
        --}}
        @php($skladnikiNaKoncie = $synchronizacja['wlaczona'])
        @php($przygotowane = $synchronizacja['przygotowane'])
        <details class="cook-ingredients" @unless($skladnikiNaKoncie) data-przygotowanie="{{ $recipe->getKey() }}" data-przygotowanie-porcje="{{ $wyborPorcji->wybrane ?? 'brak' }}" @endunless @if($skladnikiNaKoncie && session('skladniki_otwarte')) open @endif>
            <summary>Składniki ({{ $recipe->ingredients->count() }})<span class="cook-przygotowanie-skrot" data-przygotowanie-podsumowanie hidden></span></summary>
            @if($wyborPorcji->przeliczone())
                <p class="meta">Przeliczone {{ \App\Domain\Recipes\Porcje\WyborPorcji::naIle($wyborPorcji->wybrane) }}. Autor podał ilości {{ \App\Domain\Recipes\Porcje\WyborPorcji::naIle($wyborPorcji->zPrzepisu) }}.</p>
            @endif
            @if($skladnikiNaKoncie && $synchronizacja['inne_porcje'])
                <p class="cook-przygotowanie-wstep" role="status">Te ilości różnią się od zapisanych na koncie. Sprawdź je i zaznacz ponownie składniki, które masz już odmierzone.</p>
            @endif
            @if($recipe->ingredients->isEmpty())
                <p class="meta">Autor jeszcze nie dodał składników.</p>
            @else
                @if($skladnikiNaKoncie)
                    <p class="cook-przygotowanie-wstep">Zaznacz składniki, które już masz odmierzone, i kliknij „Zapisz zaznaczenie składników”. Zaznaczenie zapamiętamy na Twoim koncie, więc zobaczysz je na innych urządzeniach.</p>
                    <form method="POST" action="{{ route('cooking.sync.skladniki', $recipe->slug) }}" class="stack">
                        @csrf
                        @if($parametrPorcji !== null)<input type="hidden" name="porcje" value="{{ $parametrPorcji }}">@endif
                        <input type="hidden" name="kontekst_porcji" value="{{ $parametrPorcji ?? 'przepis' }}">
                        <input type="hidden" name="porcje_z_konta" value="{{ $synchronizacja['porcje_z_konta'] ?? 'przepis' }}">
                        <input type="hidden" name="krok" value="{{ $krok }}">
                        <input type="hidden" name="rewizja" value="{{ $synchronizacja['rewizja'] }}">
                        @foreach($przygotowane as $idPrzygotowanego)<input type="hidden" name="bylo[]" value="{{ $idPrzygotowanego }}">@endforeach
                @else
                    <p class="cook-przygotowanie-wstep" data-przygotowanie-wstep hidden>Możesz zaznaczyć składniki, które już masz odmierzone. Zaznaczenie zostaje w tej karcie przeglądarki, także po przejściu do innego kroku.</p>
                @endif
                {{--
                    GRUPY SKŁADNIKÓW I „DO SMAKU" (issue #764).
                    Ta lista pokazywała składniki płaską, jedną pętlą po
                    `$recipe->ingredients` — bez `App\Domain\Recipes\GrupySkladnikow`
                    (patrz `resources/views/pages/recipes/show.blade.php`)
                    i bez odczytania `no_amount`. Efekt: przepis z grupami
                    „Ciasto"/„Farsz" pokazywał w trybie gotowania jedną
                    listę bez nagłówków — nie usterkę widoczną na pierwszy
                    rzut oka, tylko po cichu zgubioną strukturę, którą autor
                    świadomie wpisał — a „sól do smaku" w trybie gotowania
                    wyglądało jak składnik bez żadnej ilości, bez słowa
                    wyjaśnienia, czy to pominięcie autora, czy zamierzone.
                    Naprawa czyta ten sam układ co strona przepisu, tym
                    samym wywołaniem `GrupySkladnikow::ulozyc()` — jedno
                    miejsce liczące grupy, nie dwie kopie tej samej reguły.
                --}}
                @foreach(\App\Domain\Recipes\GrupySkladnikow::ulozyc($recipe->ingredients) as $grupaSkladnikow)
                    @if($grupaSkladnikow['nazwa'] !== null)
                        <h3 class="naglowek-grupy">{{ $grupaSkladnikow['nazwa'] }}</h3>
                    @endif
                    <ul class="ingredient-list">
                        @foreach($grupaSkladnikow['skladniki'] as $ingredient)
                            @php($przeliczony = $wyborPorcji->przelicz($ingredient))
                            @php($przygotowany = $skladnikiNaKoncie && in_array((string) $ingredient->getKey(), $przygotowane, true))
                            <li data-skladnik="{{ $ingredient->getKey() }}" @class(['cook-skladnik-przygotowany' => $przygotowany])>
                                {{-- Etykieta obejmuje cały wiersz — pole, treść,
                                     notatkę i zamiennik — więc cel dotyku to cała
                                     linia składnika, nie sam kwadracik. --}}
                                <label class="cook-skladnik">
                                <input type="checkbox" class="cook-skladnik-pole" data-przygotowanie-pole @if($skladnikiNaKoncie) name="zaznaczone[]" value="{{ $ingredient->getKey() }}" @checked($przygotowany) @else hidden @endif>
                                <span class="cook-skladnik-tresc">
                                @if($przeliczony->zmieniony){{ $przeliczony->przed }}<strong class="skladnik-przeliczony">{{ $przeliczony->ilosc }}</strong>{{ $przeliczony->po }}@else{{ $ingredient->ingredient_text }}@endif
                                {{-- „do smaku” tylko wtedy, gdy autor NIE napisał
                                     tego sam w tekście składnika (issue #44).
                                     DOPISEK JEST CELOWY I TYLKO TUTAJ (D-232): to widok
                                     roboczy przy garnku, gdzie gołe „sól” wygląda jak
                                     brak informacji. Strona przepisu dopisku NIE daje
                                     i tak ma zostać — nie zbieraj tych wierszy w jeden. --}}
                                @if($ingredient->no_amount && ! str_contains(mb_strtolower($ingredient->ingredient_text), 'do smaku'))
                                    <span class="meta"> — do smaku</span>
                                @endif
                                @if($ingredient->note)<span class="meta"> — {{ $ingredient->note }}</span>@endif
                                @if($ingredient->substitutes)<span class="skladnik-zamiennik">Zamiast tego: {{ $ingredient->substitutes }}</span>@endif
                                </span>
                                {{-- Stan słowem, nie tylko znaczkiem pola i kolorem.
                                     `aria-hidden`: czytnik ekranu dostaje ten sam stan
                                     z pola („zaznaczone”), bez powtórzenia. --}}
                                <span class="cook-skladnik-stan" data-przygotowanie-stan aria-hidden="true" @unless($przygotowany) hidden @endunless>Przygotowane</span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
                {{-- Wyczyszczenie dotyczy WYŁĄCZNIE tej checklisty: to zwykły
                     przycisk skryptu, nie formularz, więc nie dotyka odhaczeń
                     kroków w sesji (te kasuje tylko „Zacznij od początku”). --}}
                @if($skladnikiNaKoncie)
                        <p class="cook-przygotowanie-licznik">Zapisane na koncie: {{ count($przygotowane) }} z {{ $recipe->ingredients->count() }}. Odznacz pole i zapisz, żeby usunąć składnik z listy przygotowanych.</p>
                        <button type="submit" class="btn btn-secondary">Zapisz zaznaczenie składników</button>
                    </form>
                @else
                <div class="cook-przygotowanie-akcje" data-przygotowanie-akcje hidden>
                    <p class="cook-przygotowanie-licznik" data-przygotowanie-licznik aria-live="polite"></p>
                    <button type="button" class="btn btn-secondary cook-przygotowanie-wyczysc" data-przygotowanie-wyczysc hidden>Wyczyść zaznaczenie składników</button>
                </div>
                @endif
            @endif
        </details>

        {{--
            Przełącznik Wake Locka. Ukryty atrybutem `hidden` w znaczniku —
            odkrywa go WYŁĄCZNIE skrypt niżej, i to tylko wtedy, gdy
            `navigator.wakeLock` naprawdę istnieje w tej przeglądarce.
            Bez JavaScriptu ten blok nigdy się nie pokazuje — czyli nigdy
            nie obiecuje działania, którego nie ma (issue: „brak wsparcia
            nie może niczego psuć").

            Checkbox przychodzi z serwera ZAWSZE odznaczony: wybór z poprzedniego
            kroku odtwarza skrypt z `sessionStorage` tej karty (issue #1302),
            dopiero po ponownej, udanej albo jawnie odrzuconej prośbie.
        --}}
        <div id="cook-wakelock-wrap" data-wakelock-recipe="{{ $recipe->slug }}" hidden>
            <label class="cook-wakelock" for="cook-wakelock-checkbox">
                <input type="checkbox" id="cook-wakelock-checkbox">
                <span>Nie usypiaj ekranu podczas gotowania</span>
            </label>
            <p class="cook-wakelock-status" id="cook-wakelock-status" aria-live="polite"></p>
        </div>

        <section class="cook-step" aria-label="Bieżący krok">
            <p class="cook-step-numer" aria-hidden="true">Krok {{ $krok }} z {{ $total }}</p>
            <p class="cook-step-tekst">{{ $aktualnyKrok->instruction }}</p>

            @if($aktualnyKrok->media)
                <div class="cook-step-zdjecie">
                    {{-- Wymiana odrzuconego zdjęcia kroku przez Policy, tylko dla aktywnego konta (#752).
                         Bez `loading="lazy"` (issue #1368): to jedyne zdjęcie treści
                         tego dokumentu, zaraz pod krótką zwykle instrukcją, a osoba
                         weszła w ten krok właśnie po nie. `fetchpriority` zostaje
                         domyślne — nie mierzyliśmy, że to element LCP.
                         Opis dla czytnika (issue #1304): własny opis autora, a bez
                         niego sam kontekst kroku — nie pusty `alt`, który każe
                         czytnikowi pominąć treść instrukcji. --}}
                    <x-photo :media="$aktualnyKrok->media" variant="feed" class="post-photo"
                             :leniwie="false"
                             :alt="$aktualnyKrok->media->alt_text ?: 'Zdjęcie do kroku '.$krok"
                             tresc="przepis"
                             :wymien-url="auth()->user()?->isActive() && auth()->user()->can('update', $recipe) ? route('recipes.edit', $recipe->slug).'#f-steps-'.($krok - 1).'-photo' : null" />
                </div>
            @endif

            @if($timerLabel)
                <div class="cook-timer" data-timer-recipe="{{ $recipe->slug }}" data-timer-krok="{{ $krok }}" data-timer-sekundy="{{ $aktualnyKrok->timer_seconds }}" data-timer-etykieta="{{ $timerLabel }}">
                    {{-- Baza, bez JS: samo zdanie mówi, co zrobić z minutnikiem
                         w kuchni, na piecyku albo telefonie. --}}
                    <p>Ustaw sobie kuchenny minutnik na {{ $timerLabel }}.</p>
                    {{-- Ulepszenie: odkrywane skryptem, licznik w tej samej
                         przeglądarce, z dźwiękiem i wibracją na koniec. --}}
                    <button type="button" class="btn btn-secondary btn-cook cook-timer-start" hidden>
                        Uruchom minutnik w tej przeglądarce
                    </button>
                    {{-- Nazwa „Pozostały czas” (#492, decyzja właściciela z 29.09.2026,
                         D-333): `role="timer"` bez nazwy czytnik ogłaszał jako
                         samą liczbę, bez informacji, co odlicza. `aria-label`,
                         a nie widoczny podpis, bo zdanie nad przyciskiem już mówi,
                         na ile ustawiono minutnik. --}}
                    <p class="cook-timer-odliczanie" role="timer" aria-label="Pozostały czas" aria-live="off" hidden></p>
                    {{--
                        Świadome anulowanie (issue #755). Bez tego przycisku
                        jedynym sposobem na przerwanie odliczania było
                        doczekanie dźwięku albo opuszczenie trybu gotowania
                        — a krok bywa zrobiony wcześniej, niż mówił minutnik
                        (danie zdjęte z ognia na oko, nie na czas). Osobny
                        przycisk, nie to samo „Uruchom” w roli przełącznika:
                        dwa różne czasowniki są jaśniejsze niż jeden
                        przycisk, który zmienia znaczenie w locie.
                    --}}
                    <button type="button" class="btn btn-secondary btn-cook cook-timer-anuluj" hidden>
                        Anuluj minutnik
                    </button>
                    <p class="visually-hidden cook-timer-komunikat" aria-live="assertive"></p>
                </div>
            @endif

            {{--
                Odhaczenie kroku — issue: „zapamiętane przy przypadkowym
                wyjściu". Trzyma się w sesji (patrz CookingModeController),
                nie w tym adresie, więc przeżywa odświeżenie strony.

                JEDEN PRZYCISK, NIE CHECKBOX + „Zapisz": ukryte pole niesie
                wartość PRZECIWNĄ do obecnego stanu, więc kliknięcie od razu
                przełącza stan w jednym POST-cie — bez JavaScriptu to wciąż
                jest jeden dotyk, nie dwa.
            --}}
            <form method="POST" action="{{ route('cooking.zaznacz', $recipe->slug) }}" class="cook-zaznacz">
                @csrf
                @if($parametrPorcji !== null)<input type="hidden" name="porcje" value="{{ $parametrPorcji }}">@endif
                <input type="hidden" name="krok" value="{{ $krok }}">
                {{-- Tożsamość kroku, nie sam numer (issue #756): po zmianie kolejności przez autora numer wskazywałby inną czynność. --}}
                <input type="hidden" name="krok_id" value="{{ $aktualnyKrok->getKey() }}">
                @if($synchronizacja['rewizja'] !== null)<input type="hidden" name="rewizja" value="{{ $synchronizacja['rewizja'] }}">@endif
                <input type="hidden" name="zrobiono" value="{{ $krokZrobiony ? '0' : '1' }}">
                <button type="submit" class="btn {{ $krokZrobiony ? 'btn-secondary' : 'btn-primary' }} btn-cook">
                    @if($krokZrobiony)
                        Zrobione ✓ — kliknij, żeby cofnąć
                    @else
                        Oznacz krok jako zrobiony
                    @endif
                </button>
            </form>
        </section>

        {{--
            Nawigacja krokami — zwykłe linki `?krok=N`, nie POST (issue:
            „to jest w porządku i jest tańsze niż cokolwiek innego"). Sama
            zmiana kroku niczego nie zapisuje, więc GET jest tu poprawnym
            czasownikiem HTTP, nie tylko tanim.

            TEKST, NIE SAME STRZAŁKI (docs/UX_50_PLUS.md, AGENTS.md: ikona
            nigdy sama) — strzałka obok jest ozdobą, `aria-hidden`, nie
            jedynym nośnikiem znaczenia.
        --}}
        <nav class="cook-nav" aria-label="Nawigacja krokami przepisu">
            @if($nastepnyKrok)
                <a class="btn btn-primary btn-cook" href="{{ $adresGotowania($nastepnyKrok) }}">
                    Następny krok <span aria-hidden="true">→</span>
                </a>
            @endif
            @if($poprzedniKrok)
                <a class="btn btn-secondary btn-cook" href="{{ $adresGotowania($poprzedniKrok) }}">
                    <span aria-hidden="true">←</span> Poprzedni krok
                </a>
            @endif
        </nav>

        {{-- Gotowanie kilku potraw naraz (#2379): przycisk odkrywa skrypt. --}}
        <x-kolejka-dodaj :recipe="$recipe" />

        @if($nastepnyKrok === null)
            {{--
                Ostatni krok — issue: „Ugotowałem" jako naturalne domknięcie,
                najlepszy moment na zdjęcie efektu. Widoczne tylko
                osobom dopuszczonym przez tę samą Policy co formularz.
            --}}
            <section class="cook-finish">
                <h2 class="mt-0">To już ostatni krok.</h2>
                @can('cook', $recipe)
                    <p>Koniec gotowania? To najlepszy moment, żeby dodać zdjęcie efektu.</p>
                    {{-- D-332: przy formie żeńskiej „Ugotowałam”; nazwą funkcji zostaje „Ugotowałem”. --}}
                    <a class="btn btn-primary btn-cook" href="{{ route('cooked.create', $recipe->slug) }}" data-minutniki-koniec>{{ \App\Support\Forma::dla(auth()->user(), 'Ugotowałam', 'Ugotowałem', 'Ugotowałem') }}</a>
                @else
                    @guest
                        <p>Załóż konto, żeby dać znać autorowi, że Ci wyszło.</p>
                        <a class="btn btn-primary btn-cook" href="{{ route('register', ['cook_recipe' => $recipe->getKey()]) }}">Załóż konto</a>
                    @endguest
                @endcan
            </section>
        @endif
        {{--
            ZAPAMIĘTYWANIE POSTĘPU NA KONCIE (#2016). Domyślnie wyłączone: postęp
            zostaje w tej przeglądarce, jak dotąd. Włącza się je świadomie, osobno
            dla każdego przepisu; goście tej sekcji nie widzą (nie mają konta,
            na którym dałoby się cokolwiek zapamiętać). Zwykłe formularze — działają
            bez JavaScriptu.
        --}}
        @if($synchronizacja['wlaczona'])
            <section class="cook-sync stack" aria-label="Zapamiętywanie postępu na koncie">
                <p class="m-0">Postęp tego przepisu — odhaczone kroki, składniki „przygotowane” i wybrana liczba porcji — jest zapamiętywany na Twoim koncie i widać go na innych urządzeniach. Minutniki zostają w tej przeglądarce. Zapis wygasa po {{ $synchronizacja['godziny'] }} godzinach od ostatniej zmiany (teraz: do {{ \App\Support\Czas::data($synchronizacja['wygasa'], 'j F, H:i') }}).</p>
                @if($wyborPorcji->dostepny())
                    <form method="POST" action="{{ route('cooking.sync.porcje', $recipe->slug) }}" class="stack">
                        @csrf
                        <input type="hidden" name="krok" value="{{ $krok }}">
                        <p class="m-0">Liczba porcji: {{ \App\Domain\Recipes\Porcje\WyborPorcji::etykieta((float) $wyborPorcji->wybrane) }}.</p>
                        @if($wyborPorcji->mniej() !== null)<button type="submit" name="wybor" value="{{ $wyborPorcji->mniej() }}" class="btn btn-secondary">Mniej porcji</button>@endif
                        @if($wyborPorcji->wiecej() !== null)<button type="submit" name="wybor" value="{{ $wyborPorcji->wiecej() }}" class="btn btn-secondary">Więcej porcji</button>@endif
                        @if($wyborPorcji->przeliczone())<button type="submit" name="wybor" value="przepis" class="btn btn-secondary">Porcje z przepisu</button>@endif
                    </form>
                @endif
                <form method="POST" action="{{ route('cooking.sync.wylacz', $recipe->slug) }}">
                    @csrf
                    @if($parametrPorcji !== null)<input type="hidden" name="porcje" value="{{ $parametrPorcji }}">@endif
                    <input type="hidden" name="krok" value="{{ $krok }}">
                    <button type="submit" class="btn btn-secondary">Wyłącz zapamiętywanie na koncie i usuń zapis</button>
                </form>
            </section>
        @elseif($synchronizacja['mozna_wlaczyc'])
            <section class="cook-sync stack" aria-label="Zapamiętywanie postępu na koncie">
                <p class="m-0">Chcesz dokończyć gotowanie na innym urządzeniu? Zapamiętamy odhaczone kroki tego przepisu na Twoim koncie na {{ $synchronizacja['godziny'] }} godzin od ostatniej zmiany. Domyślnie postęp zostaje tylko w tej przeglądarce.</p>
                <form method="POST" action="{{ route('cooking.sync.wlacz', $recipe->slug) }}">
                    @csrf
                    @if($parametrPorcji !== null)<input type="hidden" name="porcje" value="{{ $parametrPorcji }}">@endif
                    <input type="hidden" name="krok" value="{{ $krok }}">
                    <button type="submit" class="btn btn-secondary">Zapamiętuj postęp na moim koncie</button>
                </form>
            </section>
        @endif
        @if($hasProgress)
            <div class="danger-zone">
                <details class="confirm">
                    <summary class="btn btn-secondary">Zacznij od początku</summary>
                    <div class="confirm-body stack">
                        <p>Usunąć odhaczenia wszystkich kroków tego przepisu? Pozostałe przepisy i zapisane wykonania zostaną bez zmian.</p>
                        <a class="btn btn-secondary" href="{{ $adresGotowania($krok) }}">Zostaw odhaczenia</a>
                        <form method="POST" action="{{ route('cooking.restart', $recipe->slug) }}">
                            @csrf
                            @if($parametrPorcji !== null)<input type="hidden" name="porcje" value="{{ $parametrPorcji }}">@endif
                            <button type="submit" class="btn btn-danger">Usuń odhaczenia i zacznij od początku</button>
                        </form>
                    </div>
                </details>
            </div>
        @endif
    </article>
</x-layout>
