<?php

declare(strict_types=1);

namespace App\Domain\Search;

use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;
use App\Support\ProgPodobienstwa;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Wyszukiwarka MVP: PostgreSQL + pg_trgm + unaccent. Bez Typesense,
 * bez Meilisearch, bez osobnego indeksu (docs/ARCHITECTURE.md).
 *
 * WSZYSTKIE porównania idą po KOLUMNACH `*_search` — generowanych
 * (`GENERATED ALWAYS AS (public.kuking_normalize(...)) STORED`) w migracji
 * `2026_09_09_100000_materialize_search_columns`. To na nich stoją indeksy
 * GIN. Zapytanie MUSI pytać dokładnie o to, na czym stoi indeks, inaczej
 * PostgreSQL go nie użyje i każde wyszukiwanie skanuje całą tabelę.
 * Jeśli zmieniasz tu porównanie — zmień też migrację.
 *
 * DLACZEGO KOLUMNA, A NIE WYRAŻENIE `kuking_normalize(title)`
 * Indeks na wyrażeniu też działa (tak było do 9 września 2026) — ale indeks
 * GIN dla `%` jest STRATNY: oddaje kandydatów, których PostgreSQL sprawdza
 * po raz drugi już na wierszu tabeli. Przy progu 0,12 kandydatów jest
 * 35–60% tabeli, a każdy recheck liczył `unaccent()` od nowa. Zmierzone
 * na 10 000 kont / 40 000 przepisów / 80 000 wpisów, fraza „pierogi":
 * 169,7 ms → 59,1 ms, ten sam wynik co do wiersza. Uzasadnienie i plany
 * zapytań: komentarz tamtej migracji i `docs/research/WYDAJNOSC.md` §3.4.
 *
 * Dlaczego trigramy, a nie pełnotekstowe FTS jako główna ścieżka: nasi
 * użytkownicy wpisują "zurek" szukając "żurku" i "pierogii" szukając
 * "pierogów". Podobieństwo trigramowe radzi sobie z literówkami i odmianą
 * lepiej niż stemming, którego dla polskiego w Postgresie po prostu nie ma.
 */
final class SearchQuery
{
    public const MAX_PHRASE_LENGTH = 120;

    /** Wspólna granica dla formularzy GET i bezpośrednich wywołań domeny. */
    public static function phraseValidator(string $phrase, string $label = 'Czego szukasz?'): ValidatorContract
    {
        return Validator::make(
            ['q' => $phrase],
            ['q' => ['max:'.self::MAX_PHRASE_LENGTH]],
            ['q.max' => 'Skróć tekst w polu „:attribute” do :max znaków i spróbuj ponownie.'],
            ['q' => $label],
        );
    }

    /** Pojedyncze początkowe @ to zapis nazwy widoczny na profilu (#886). */
    public static function peoplePhrase(string $phrase): string
    {
        $phrase = trim($phrase);

        return str_starts_with($phrase, '@') && ! str_starts_with($phrase, '@@')
            ? substr($phrase, 1)
            : $phrase;
    }

    // PRÓG PODOBIEŃSTWA MIESZKA W `App\Support\ProgPodobienstwa`, nie tutaj.
    //
    // Do 7 września 2026 stała `SIMILARITY_THRESHOLD = 0.12` była w tym pliku
    // — i NIGDY nie była używana. Operator `%` nie przyjmuje progu jako
    // argumentu, bierze go z ustawienia sesji, którego nikt nie ustawiał,
    // czyli z domyślnego 0.3. Zmierzone: literówka „sernk" nie znajdowała
    // „Sernika babci Haliny", mimo że podobieństwo wynosiło 0.1818, czyli
    // POWYŻEJ udokumentowanego progu. Cała odporność na literówki, którą
    // obiecuje komentarz klasy, była martwa.
    //
    // Od 9 września 2026 (issue #187) próg dotyczy operatora `<%`
    // (`word_similarity`), nie `%`, i wynosi 0,5. Uzasadnienie liczby,
    // pomiar i to, CO ta zmiana gubi — w komentarzu tamtej klasy
    // i w `docs/research/WYDAJNOSC.md` §3.4b.

    /**
     * Identyfikatory przepisów pasujących do frazy — CZTERY OSOBNE ZAPYTANIA
     * sklejone przez `UNION ALL`, a nie jeden warunek z `OR` (issue #116).
     *
     * DLACZEGO NIE `OR`
     * Cztery warunki na czterech różnych kolumnach (a jeden na innej tabeli)
     * połączone przez `OR` nie dają się PostgreSQL złożyć w jeden plan
     * z czterech indeksów. Planner poddawał się i skanował całe tabele —
     * przy większej liczbie kont brał `users` jako sterownik pętli, więc koszt
     * wyszukiwania rósł z liczbą KONT W SERWISIE, a nie z liczbą trafień.
     * Indeksy trigramowe dla wszystkich czterech warunków istnieją od migracji
     * `2026_09_05_001300` — problemem był kształt zapytania, nie brak indeksu.
     *
     * Rozbite na gałęzie — każda dostaje własny skan, który może pójść po
     * indeksie. Zmierzone (PostgreSQL 16.13, bufory ciepłe, mediana z 5
     * przebiegów `EXPLAIN (ANALYZE, FORMAT JSON)`, fraza „pierogi"):
     *
     *     100 kont /   200 przepisów    1,57 ms →  0,49 ms
     *    1000 kont /  2000 przepisów   14,04 ms →  3,10 ms
     *    5000 kont / 10000 przepisów   68,16 ms → 14,12 ms
     *
     * UCZCIWA UWAGA DO ISSUE: na PostgreSQL 16.13 nie odtworzyłem planu,
     * w którym `users` steruje pętlą po `recipes` — przy stałej liczbie
     * przepisów, a rosnącej liczbie kont (100 → 5000) stara wersja trzymała
     * się 13,4–14,5 ms. Odtworzyłem PRZYCZYNĘ opisaną w issue: warunek z `OR`
     * nie sięgał po żaden z indeksów na `recipes` i czytał tabelę w całości,
     * więc koszt zależał od jej ROZMIARU, a nie od liczby trafień. To jest
     * naprawione i to pilnuje test.
     *
     * DRUGI POMIAR, 9 WRZEŚNIA 2026, DZIESIĘĆ RAZY WIĘKSZA BAZA
     * 10 000 kont / 40 000 przepisów / 80 000 wpisów. Wszystkie cztery gałęzie
     * idą po `Bitmap Index Scan` — indeks NIE jest pomijany, teza z tytułu
     * issue jest na tej skali obalona. `users` nie steruje niczym: jest
     * budowaną raz stroną `Hash Join` (9 500 wierszy, ~3 ms), więc koszt nie
     * rośnie z liczbą kont. Rośnie natomiast z liczbą PRZEPISÓW — i to
     * z powodu, którego issue nie przewidziało: recheck stratnego indeksu GIN
     * (patrz komentarz klasy). Stąd kolumny `*_search`.
     *
     * `UNION ALL`, nie `UNION`: usuwanie duplikatów nie zmienia wyniku `IN`,
     * a kosztuje `HashAggregate` — na tyle, że planner wracał do skanowania
     * sekwencyjnego dwóch gałęzi (10,9 ms kontra 2,8 ms na samym zapytaniu
     * kandydatów).
     *
     * To jest zmiana PLANU, nie semantyki: zbiór pasujących przepisów jest
     * dokładnie ten sam co przy `OR`, razem z sortowaniem po podobieństwie
     * na całości wyniku. Dlatego świadomie nie ma tu limitu ani osobnej rundy
     * „najpierw tytuł, doszukaj resztę tylko gdy mało wyników" — tamto
     * zmieniałoby kolejność wyników przy nielicznych trafieniach w tytule.
     *
     * PIERWSZA GAŁĄŹ UŻYWA `<%`, NIE `%` (issue #187) — I TO JEST ZMIANA
     * TRAFNOŚCI, NIE KOSZTU
     * `fraza <% title_search` pyta, czy fraza jest podobna do najlepiej
     * pasującego FRAGMENTU tytułu; `%` pytało o podobieństwo do CAŁEGO
     * tytułu i przy progu 0,12 łączyło ze sobą rzeczy, które nie mają ze sobą
     * nic wspólnego („rosół" → „Rogaliki", „pierogi" → „Piernik", „sajgonki
     * z krewetkami" → 1 526 wierszy w bazie bez jednej sajgonki). Zmierzone
     * PRZED/PO, ta sama baza, 40 000 przepisów, fraza „pierogi":
     *
     *     %  @0,12   18 178 kandydatów z indeksu → 2 798 trafień, 62,2 ms
     *     <% @0,5     2 134 kandydatów           → 2 073 trafienia, 9,9 ms
     *
     * Kolumna i indeks zostają te same (`gin_trgm_ops` obsługuje oba
     * operatory) — ta zmiana NIE dotyka schematu bazy.
     *
     * ⚠️ Fraza jest po LEWEJ stronie operatora. `title_search <% ?` znaczy coś
     * innego (czy tytuł jest podobny do fragmentu frazy) i indeks przestałby
     * pasować do zapytania. Zamiana stron to najłatwiejszy sposób, żeby cicho
     * zepsuć tę wyszukiwarkę.
     */
    private const KANDYDACI_SQL = <<<'SQL'
        SELECT id FROM recipes WHERE ? <% title_search
        UNION ALL
        SELECT id FROM recipes WHERE title_search LIKE ?
        UNION ALL
        SELECT id FROM recipes WHERE summary_search LIKE ?
        UNION ALL
        SELECT recipe_id FROM recipe_ingredients WHERE ingredient_text_search LIKE ?
        SQL;

    /**
     * KURSOR RANKINGU ZAMIAST SAMEGO `OFFSET` (issue #1023)
     *
     * Dalsze okno wyników (po 200) to osobne żądanie HTTP, a PostgreSQL
     * w `Read Committed` widzi w nim NOWY obraz danych. Liczbowy `OFFSET`
     * liczy pozycje od góry AKTUALNEGO rankingu: jedno świeże trafienie nad
     * granicą okna przesuwało dawny wynik 200 na 201 i pokazywało go drugi
     * raz, a jedno zniknięcie wyżej wciągało dawny 201 do już obejrzanych
     * i gubiło go na zawsze.
     *
     * Kursor zapisuje KLUCZ SORTOWANIA ostatniego pokazanego rekordu, nie
     * jego numer. Klucz rekordu zależy wyłącznie od niego samego i od frazy
     * — nie od sąsiadów — więc dopisanie, zniknięcie czy zmiana rankingu
     * INNEGO trafienia nie przesuwa granicy okna. Stan żyje w adresie
     * (kilkadziesiąt znaków), nie w sesji ani w transakcji otwartej między
     * żądaniami; nie ma też kolekcji w PHP — to zwykły `WHERE` na tym samym
     * zapytaniu.
     *
     * `cursorPaginate()` Laravela tu nie pasuje: klucz sortowania to
     * wyrażenia z parametrem (`word_similarity(?, …)`), a tamten mechanizm
     * wymaga kolumn albo aliasów bez parametrów (issue #1023, źródła).
     *
     * Format przepisu: `ws_s_mikrosekundy_uuid`. Obie miary to `real`
     * odczytany z bazy w najkrótszej dokładnej postaci tekstowej
     * (`extra_float_digits` domyślne od PostgreSQL 12), więc `?::real`
     * odtwarza DOKŁADNIE tę samą liczbę — remisy rozstrzygają się tak samo
     * jak w `ORDER BY`. Czas publikacji w mikrosekundach, nie tekstem daty:
     * liczbę da się sprawdzić wyrażeniem regularnym, a zły tekst daty
     * wywróciłby zapytanie błędem 500.
     */
    private const KURSOR_PRZEPISU = '/\A([0-9][0-9.e+-]{0,15})_([0-9][0-9.e+-]{0,15})_(-?[0-9]{1,18})_([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\z/';

    /** Format osoby: `s_uuid` — ten sam porządek co `ORDER BY` w people(). */
    private const KURSOR_OSOBY = '/\A([0-9][0-9.e+-]{0,15})_([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\z/';

    /** Klucz sortowania przepisu zwróconego przez recipes() — do adresu „Pokaż więcej". */
    public static function kursorPrzepisu(Recipe $recipe): string
    {
        return implode('_', [
            $recipe->getRawOriginal('kursor_ws'),
            $recipe->getRawOriginal('kursor_s'),
            $recipe->getRawOriginal('kursor_czas'),
            $recipe->getKey(),
        ]);
    }

    /** Klucz sortowania osoby zwróconej przez people(). */
    public static function kursorOsoby(Profile $profile): string
    {
        return $profile->getRawOriginal('kursor_s').'_'.$profile->getKey();
    }

    /**
     * Null, gdy kursor jest nieczytelny — wtedy obowiązuje `offset`.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}|null
     */
    private static function czytajKursorPrzepisu(?string $kursor): ?array
    {
        if ($kursor === null || preg_match(self::KURSOR_PRZEPISU, $kursor, $m) !== 1
            || ! self::miara($m[1]) || ! self::miara($m[2])) {
            return null;
        }

        return [$m[1], $m[2], $m[3], $m[4]];
    }

    /** @return array{0: string, 1: string}|null */
    private static function czytajKursorOsoby(?string $kursor): ?array
    {
        if ($kursor === null || preg_match(self::KURSOR_OSOBY, $kursor, $m) !== 1 || ! self::miara($m[1])) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    /** Podobieństwo trigramowe jest zawsze w [0, 1]; wszystko inne to nie nasz kursor. */
    private static function miara(string $wartosc): bool
    {
        return is_numeric($wartosc) && (float) $wartosc >= 0.0 && (float) $wartosc <= 1.0;
    }

    /**
     * @param  User|null  $widz  kto szuka — widoczność (#1320), blokady i licznik ugotowań
     * @param  string|null  $po  kursor z kursorPrzepisu(); gdy czytelny, zastępuje `offset`
     * @return Collection<int, Recipe>
     */
    public function recipes(string $phrase, ?User $widz = null, int $limit = 20, ?int $maksMinut = null, int $offset = 0, ?string $po = null): Collection
    {
        $phrase = trim($phrase);
        self::phraseValidator($phrase)->validate();

        if (mb_strlen($phrase) < 2) {
            return new Collection;
        }

        $needle = $this->normalize($phrase);

        // Metaznaki LIKE (`%`, `_`, znak ucieczki `\`) z frazy MUSZĄ zostać
        // dosłownym tekstem, nie operatorem wzorca (issue #753). Wyłącznie
        // dla trzech gałęzi `LIKE` niżej — pierwsza gałąź trigramowa (`<%`)
        // dostaje `$needle` BEZ ucieczki, bo to nie jest LIKE i cytowanie
        // zepsułoby dopasowanie podobieństwa/sortowanie po nim.
        $literalnie = $this->uciecznijLike($needle);

        // Bez tego gałąź trigramowa niżej milczy przy literówkach — patrz
        // komentarz przy zniesionej stałej wyżej.
        ProgPodobienstwa::ustaw();

        $kursor = self::czytajKursorPrzepisu($po);
        $ws = 'word_similarity(?, recipes.title_search)';
        $s = 'similarity(recipes.title_search, ?)';
        $czas = '(extract(epoch from recipes.published_at) * 1000000)::bigint';

        return Recipe::query()
            ->select('recipes.*')
            ->selectRaw("{$ws} AS kursor_ws, {$s} AS kursor_s, {$czas} AS kursor_czas", [$needle, $needle])
            // TEN SAM ZBIÓR, KTÓRY WIDZ MOŻE OTWORZYĆ (issue #1320).
            //
            // Do tej pory było tu `publiclyVisible()` — także dla zalogowanej
            // osoby. Obserwująca nie znajdowała po tytule przepisu „dla
            // obserwujących", który otwierała z profilu, a autor własnego
            // przepisu „tylko dla mnie". `widoczneDla($widz)` to granica
            // `RecipePolicy::view` w SQL: gość i obcy dostają dokładnie to,
            // co dawniej (publiczne), obserwujący — także `followers`,
            // autor — także swoje `private`.
            //
            // `published()` PRZED `widoczneDla()` i to jest obowiązkowe:
            // sam scope wpuszcza autorowi jego SZKICE (zeszyt ich potrzebuje),
            // a pokazanie szkiców w wyszukiwarce to osobna decyzja produktowa,
            // nie część tej poprawki.
            //
            // Moderator świadomie NIE dostaje tu szerszego zbioru niż zwykła
            // osoba — Policy wpuszcza go pod adres, ale wyszukiwarka nie jest
            // narzędziem moderacji.
            //
            // Koszt zmierzony (mediana 7 × `EXPLAIN ANALYZE`, 200 autorów,
            // 20 000 przepisów, widz obserwuje 50 autorów, fraza „pierogi"):
            // gość 59,9 → 61,3 ms, zalogowana 62,5 → 61,3 ms. Wyznacza go
            // nadal zbiór kandydatów z `KANDYDACI_SQL`, nie `follows`.
            ->published()
            ->widoczneDla($widz)
            // Konto autora aktywne (audyt A5) — bez tego wyszukiwarka
            // wypychała przepisy osoby zawieszonej albo zbanowanej na widok
            // każdego, kto akurat wpisał trafną frazę, mimo że jej profil
            // (link pod wynikiem) dawał 403.
            ->whereHas('author', fn ($query) => $query->where('status', User::STATUS_ACTIVE))
            ->tap(fn ($query) => $this->pomijajZablokowanych($query, $widz, 'recipes.author_id'))
            ->with(Recipe::RELACJE_KARTY)
            // `widoczneDla($widz)` W LICZNIKU, nie gołe `withCount`.
            //
            // Karta wyniku pokazuje „Ugotowane N ×" tą samą etykietą, którą
            // pokazuje strona przepisu — a tamta liczy od dziś tylko wykonania
            // widoczne dla TEGO widza (audyt przepisów: gołe `count()` stało
            // dziesięć linijek pod galerią, która filtr miała od audytu A4,
            // i zdradzało istnienie wykonania osoby zablokowanej). Bez tej
            // samej granicy tutaj ta sama etykieta znaczyłaby dwie różne
            // rzeczy zależnie od ekranu — czyli ta klasa błędu przeniesiona
            // o jeden plik dalej, a nie zamknięta.
            //
            // Dla gościa `widoczneDla(null)` nie filtruje niczego, więc
            // liczby publiczne i dane dla wyszukiwarek zostają bez zmian.
            ->withCount(['cookedEvents' => fn ($q) => $q->widoczneDla($widz)])
            ->whereRaw('recipes.id IN ('.self::KANDYDACI_SQL.')', [
                $needle,
                '%'.$literalnie.'%',
                '%'.$literalnie.'%',
                '%'.$literalnie.'%',
            ])
            // Filtr „Do 30 minut" (UI kit v2, ekran 03).
            //
            // Przepis BEZ podanych czasów wypada z tego filtra, a nie wpada.
            // Brak danych nie znaczy „szybki" — obiecanie, że coś zajmie
            // pół godziny, gdy nikt tego nie zmierzył, jest gorsze niż
            // nieujęcie przepisu w wynikach.
            ->when($maksMinut !== null, fn ($query) => $query
                ->whereNotNull('prep_minutes')
                ->whereNotNull('cook_minutes')
                ->whereRaw('(prep_minutes + cook_minutes) <= ?', [$maksMinut]))
            // KOLEJNOŚĆ: NAJPIERW TO, CO ZDECYDOWAŁO O TRAFIENIU (issue #187)
            //
            // Wiersz jest w wyniku dlatego, że fraza pasuje do FRAGMENTU
            // tytułu (`<%`), więc pierwszym kryterium jest ta sama miara,
            // `word_similarity`. Samo `similarity` (kryterium sprzed issue
            // #187) mierzy podobieństwo do CAŁEGO tytułu, czyli karze tytuł
            // za długość — a to przy operatorze `<%` wypycha prawdziwe
            // trafienia pod śmieci. Zmierzone na bazie 40 000 przepisów:
            //
            //   fraza „pierogi": przy samym `similarity` 722 przepisy
            //   „Pierogi …" stały ZA pierwszym „Piernikiem"; po zmianie: 0.
            //   fraza „sernk": przy samym `similarity` sześć „Pierników"
            //   stało przed pierwszym „Sernikiem babci Haliny"; po zmianie: 0.
            //
            // `similarity` zostaje jako DRUGIE kryterium i to nie jest ozdoba:
            // przy `word_similarity` wszystkie tytuły zawierające całe słowo
            // mają równe 1,00, więc bez tego rozstrzygnięcia „Pierogi" i
            // „Pierogi ruskie babci Haliny z pieca" byłyby nierozróżnialne
            // i o kolejności decydowałaby data. Z nim krótszy, dokładniejszy
            // tytuł wraca na górę — zmierzone: dokładny tytuł zostaje na
            // pozycji 1 tak samo jak przed zmianą.
            // Wyrażenia wpisane dosłownie, nie przez `$ws`/`$s`: rejestr
            // FeedNieSortujePoMierzeReakcjiTest pilnuje tego tekstu. Muszą być
            // identyczne z tymi w kursorze niżej.
            ->orderByRaw(
                'word_similarity(?, recipes.title_search) DESC, similarity(recipes.title_search, ?) DESC',
                [$needle, $needle],
            )
            ->orderByDesc('published_at')
            ->orderBy('recipes.id')
            // Dokładnie ten sam porządek co wyżej, zapisany jako „za kursorem":
            // trzy miary malejąco, `id` rosnąco. Zanegowane miary dają jeden
            // kierunek, więc wystarcza JEDNO porównanie wierszy — każda miara
            // liczy się raz na wiersz. Rozwinięte `a < x OR (a = x AND …)`
            // liczyło `word_similarity` do czterech razy i było zmierzalnie
            // wolniejsze od starego `OFFSET` (opis PR #1023).
            ->when($kursor !== null, fn ($query) => $query->whereRaw(
                "(-{$ws}, -{$s}, -{$czas}, recipes.id) > (-(?::real), -(?::real), -(?::bigint), ?::uuid)",
                [$needle, $needle, $kursor[0], $kursor[1], $kursor[2], $kursor[3]],
            ))
            ->offset($kursor === null ? max(0, $offset) : 0)
            ->limit($limit)
            ->get();
    }

    /**
     * Szukanie ludzi — i JEDYNE miejsce, w którym `OR` świadomie ZOSTAJE.
     *
     * Issue #116 stawiało tezę ogólną: „`OR` w `WHERE` blokuje indeks
     * trigramowy". Ta metoda jest jej próbą kontrolną i teza się na niej
     * NIE potwierdza. Trzy warunki pod wspólnym `OR`, ale wszystkie na
     * JEDNEJ tabeli — PostgreSQL składa z nich `BitmapOr` z trzech skanów
     * indeksowych i nie czyta tabeli. Zmierzone przy 10 000 kont, fraza
     * „pierogi": 7,6 ms, trzy `Bitmap Index Scan` na `profiles_*_trgm_idx`.
     *
     * `recipes()` musiało pozbyć się `OR` z innego powodu: tam czwarty
     * warunek był skorelowanym `EXISTS` na INNEJ tabeli, a takiego składnika
     * `BitmapOr` przyjąć nie może — więc cała alternatywa spadała do filtra
     * na pełnym skanie. Rozstrzyga to, czy warunki są na jednej tabeli,
     * a nie samo słowo `OR`.
     *
     * @param  User|null  $widz  kto szuka — potrzebny WYŁĄCZNIE do blokad
     * @param  string|null  $po  kursor z kursorOsoby(); gdy czytelny, zastępuje `offset` (issue #1023)
     * @return Collection<int, Profile>
     */
    public function people(string $phrase, ?User $widz = null, int $limit = 20, int $offset = 0, ?string $po = null): Collection
    {
        $phrase = trim($phrase);
        self::phraseValidator($phrase)->validate();
        $phrase = self::peoplePhrase($phrase);

        if (mb_strlen($phrase) < 2) {
            return new Collection;
        }

        $needle = $this->normalize($phrase);

        // Metaznaki LIKE dosłownie — patrz komentarz w recipes() (issue #753).
        // Ta metoda nie ma gałęzi trigramowej, więc CAŁY `$needle` idzie
        // wyłącznie przez wersję po ucieczce.
        $literalnie = $this->uciecznijLike($needle);

        // Ta metoda nie używa ŻADNEGO operatora trigramowego — dopasowuje
        // przez `LIKE`, a `similarity()` niżej tylko porządkuje wynik i progu
        // nie czyta. Wywołanie zostaje mimo to, żeby każda ścieżka
        // wyszukiwania ustawiała próg tej samej klasy: dzień, w którym ktoś
        // dopisze tu `<%` i zapomni o tej linijce, jest tańszy niż jedno
        // zaoszczędzone `set_config` na zapytanie (issue #187, punkt 3).
        ProgPodobienstwa::ustaw();

        $kursor = self::czytajKursorOsoby($po);
        $s = 'similarity(profiles.display_name_search, ?)';

        return Profile::query()
            ->select('profiles.*')
            ->selectRaw("{$s} AS kursor_s", [$needle])
            // `user.profile.avatar`, A NIE SAMO `user` — I NIE JEST TO
            // POWTÓRNE ŁADOWANIE TEGO SAMEGO WIERSZA DLA OZDOBY.
            //
            // Oba ekrany korzystające z tej metody (`/szukaj`, zakładka
            // „Ludzie", i krok onboardingu „znasz już kogoś tutaj?") rysują
            // zdjęcie komponentem `<x-avatar :user="$profil->user" />`.
            // Komponent przyjmuje KONTO i sam wraca po profil
            // (`$user?->profile`, potem `zdjecieDoPokazania()` → `avatar`),
            // a wynikiem tej metody są PROFILE — więc doładowany tu `avatar`
            // siedzi na innej instancji niż ta, po którą sięga komponent,
            // i nie oszczędza ani jednego zapytania.
            //
            // Zmierzone przed poprawką (`WynikiSzukaniaLudziBezWachlarzaZapytanTest`):
            // 16 zapytań przy 2 osobach i 34 przy 20 — dokładnie jedno
            // `select * from profiles where user_id = ?` na każdą wypisaną
            // osobę. Przy kontach ze zdjęciem profilowym dochodziło drugie,
            // po wiersz `media`.
            //
            // `avatar` na profilu-korzeniu ZOSTAJE: to jest kod domenowy,
            // a nie widok, i nie ma prawa zakładać, że każdy przyszły
            // odbiorca sięgnie po zdjęcie okrężną drogą przez konto.
            // Kosztuje to jedno zapytanie na CAŁĄ stronę wyników, nie jedno
            // na osobę.
            ->with(['user.profile.avatar', 'avatar'])
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->tap(fn ($query) => $this->pomijajZablokowanych($query, $widz, 'profiles.user_id'))
            ->where(function ($query) use ($literalnie): void {
                $query
                    ->whereRaw('display_name_search LIKE ?', ['%'.$literalnie.'%'])
                    ->orWhereRaw('username_search LIKE ?', ['%'.$literalnie.'%'])
                    ->orWhereRaw('speciality_search LIKE ?', ['%'.$literalnie.'%']);
            })
            // Tu `similarity` ZOSTAJE (issue #187 zmieniło tylko przepisy).
            // Dopasowanie idzie przez `LIKE`, więc zbiór wyników nie zależy
            // od żadnej miary podobieństwa, a nazwy profili są krótkie —
            // „karanie za długość", które psuło kolejność przepisów, nie ma
            // się tu na czym odbyć. Zmiana bez zmierzonego powodu byłaby
            // zmianą kolejności wyników za darmo.
            ->orderByRaw('similarity(profiles.display_name_search, ?) DESC', [$needle])
            ->orderBy('profiles.user_id')
            // Kursor rankingu — uzasadnienie przy KURSOR_PRZEPISU wyżej.
            ->when($kursor !== null, fn ($query) => $query->whereRaw(
                "(-{$s}, profiles.user_id) > (-(?::real), ?::uuid)",
                [$needle, $kursor[0], $kursor[1]],
            ))
            ->offset($kursor === null ? max(0, $offset) : 0)
            ->limit($limit)
            ->get();
    }

    /**
     * Wycięcie z wyników wszystkiego, co należy do osoby w relacji blokady
     * z szukającym (issue #41).
     *
     * DLACZEGO TO MUSI BYĆ TUTAJ, A NIE W POLICY
     * Wyszukiwarka to osobne zapytanie. Policy pilnuje wejścia na adres treści,
     * filtry profilu pilnują listy profilu — żadne z nich nie dotyczy tego
     * zapytania. Bez tego filtra blokada znaczyła tylko „nie zobaczę tej osoby,
     * dopóki nie użyję wyszukiwarki", co jest obietnicą bez pokrycia.
     *
     * Blokada działa w OBIE strony (`AGENTS.md` §4): nieważne, kto kogo
     * zablokował — stąd dwa warunki w `orWhere`.
     *
     * Dla gościa (`$widz === null`) nie ma czego filtrować: blokada jest relacją
     * między dwoma kontami.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $kolumnaAutora  w pełni kwalifikowana kolumna z id właściciela treści
     */
    private function pomijajZablokowanych($query, ?User $widz, string $kolumnaAutora): void
    {
        if ($widz === null) {
            return;
        }

        $query->whereNotExists(function ($sub) use ($widz, $kolumnaAutora): void {
            $sub->selectRaw('1')
                ->from('blocks')
                ->where(function ($w) use ($widz, $kolumnaAutora): void {
                    $w->where('blocks.blocker_id', $widz->getKey())
                        ->whereColumn('blocks.blocked_id', $kolumnaAutora);
                })
                ->orWhere(function ($w) use ($widz, $kolumnaAutora): void {
                    $w->whereColumn('blocks.blocker_id', $kolumnaAutora)
                        ->where('blocks.blocked_id', $widz->getKey());
                });
        });
    }

    /**
     * Fraza po stronie PHP musi być znormalizowana TAK SAMO jak kolumna
     * po stronie bazy — inaczej „Żurek" nie znajdzie „żurek".
     *
     * Str::ascii odpowiada temu, co robi `unaccent` z polskimi znakami
     * diakrytycznymi. Obie publiczne metody sprawdzają długość PRZED
     * zapytaniem. Nie obcinamy frazy: wynik ma dotyczyć całego tekstu (#885).
     */
    private function normalize(string $phrase): string
    {
        return mb_strtolower(Str::ascii($phrase));
    }

    /**
     * Cytuje metaznaki operatora LIKE, żeby fraza użytkownika trafiała do
     * `LIKE` jako dosłowny tekst, nie jako wzorzec (issue #753).
     *
     * PostgreSQL bierze `\` jako domyślny znak ucieczki dla `LIKE` — dlatego
     * najpierw trzeba podwoić SAM znak ucieczki, inaczej `\` z frazy
     * uciekałby przypadkowo następny znak wstawiony przez tę metodę.
     * Kolejność (najpierw `\`, potem `%` i `_`) jest tu obowiązkowa.
     *
     * Używać WYŁĄCZNIE dla parametrów `LIKE`. Operator trigramowy `<%`
     * i funkcje `similarity()`/`word_similarity()` mają dostawać frazę
     * bez tej ucieczki — to nie jest LIKE i cytowanie zmieniłoby dopasowanie.
     */
    private function uciecznijLike(string $wartosc): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $wartosc);
    }
}
