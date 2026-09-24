@php
    $poprzedniKrok = $krok > 1 ? $krok - 1 : null;
    $nastepnyKrok = $krok < $total ? $krok + 1 : null;
    $timerLabel = $aktualnyKrok->timerLabel(afterNa: true);
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
            <a class="btn btn-secondary cook-exit" href="{{ route('recipes.show', $recipe->slug) }}">
                Zakończ gotowanie
            </a>
        </div>

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
        <details class="cook-ingredients">
            <summary>Składniki ({{ $recipe->ingredients->count() }})</summary>
            @if($recipe->ingredients->isEmpty())
                <p class="meta">Autor jeszcze nie dodał składników.</p>
            @else
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
                            <li>
                                {{ $ingredient->ingredient_text }}
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
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            @endif
        </details>

        {{--
            Przełącznik Wake Locka. Ukryty atrybutem `hidden` w znaczniku —
            odkrywa go WYŁĄCZNIE skrypt niżej, i to tylko wtedy, gdy
            `navigator.wakeLock` naprawdę istnieje w tej przeglądarce.
            Bez JavaScriptu ten blok nigdy się nie pokazuje — czyli nigdy
            nie obiecuje działania, którego nie ma (issue: „brak wsparcia
            nie może niczego psuć").
        --}}
        <div id="cook-wakelock-wrap" hidden>
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
                    <x-photo :recipe="$recipe" :media="$aktualnyKrok->media" variant="feed" class="post-photo" />
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
                    <p class="cook-timer-odliczanie" role="timer" aria-live="off" hidden></p>
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
                <input type="hidden" name="krok" value="{{ $krok }}">
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
                <a class="btn btn-primary btn-cook" href="{{ route('cooking.show', [$recipe->slug, 'krok' => $nastepnyKrok]) }}">
                    Następny krok <span aria-hidden="true">→</span>
                </a>
            @endif
            @if($poprzedniKrok)
                <a class="btn btn-secondary btn-cook" href="{{ route('cooking.show', [$recipe->slug, 'krok' => $poprzedniKrok]) }}">
                    <span aria-hidden="true">←</span> Poprzedni krok
                </a>
            @endif
        </nav>

        @if($nastepnyKrok === null)
            {{--
                Ostatni krok — issue: „Ugotowałem" jako naturalne domknięcie,
                najlepszy moment na zdjęcie efektu. Widoczne tylko
                zalogowanym — dokładnie jak na stronie przepisu, ten sam
                warunek, żeby nie obiecywać akcji, która i tak odbije się
                o ekran logowania.
            --}}
            <section class="cook-finish">
                <h2 class="mt-0">To już ostatni krok.</h2>
                @auth
                    <p>Koniec gotowania? To najlepszy moment, żeby dodać zdjęcie efektu.</p>
                    <a class="btn btn-primary btn-cook" href="{{ route('cooked.create', $recipe->slug) }}">Ugotowałem</a>
                @else
                    <p>Załóż konto, żeby dać znać autorowi, że Ci wyszło.</p>
                    <a class="btn btn-primary btn-cook" href="{{ route('register') }}">Załóż konto</a>
                @endauth
            </section>
        @endif
    </article>
</x-layout>
