<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Feed nie jest algorytmiczny — AGENTS.md §8 i §12 (R73, D-275), pilnowane automatem.
 *
 * Od D-275 (#1806) AGENTS.md §8 ma ZAMKNIĘTĄ listę dozwolonych reguł doboru:
 * czas, równość autorów, oznaczony wybór gospodarza, bramki i blokady, jawne
 * polecenia widza. Ten plik pilnuje jej mierzalnej części — żadna lista nie
 * jest układana po mierze cudzych reakcji. Zasięg: `app/Domain/Feed`,
 * `app/Domain/Digest` (tygodniowy list to czwarta powierzchnia z wpisami,
 * obok Obserwowanych, Odkrywania i tablicy) i każdy plik z `publiclyVisible()`.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  CO TEN TEST MIERZY I DLACZEGO AKURAT TO
 * ═══════════════════════════════════════════════════════════════════════
 *
 * §12 zakazuje sześciu rzeczy naraz. Ten test pilnuje JEDNEJ — feedu
 * algorytmicznego — bo koszt pomyłki jest tam najwyższy, a pozostałe pięć
 * albo już ma strażnika (liczniki: D-081), albo da się „zmierzyć" wyłącznie
 * gripem po nazwach. Grep po słowie `streak` przechodzi na zielono i nie
 * pilnuje niczego: kto doda gamifikację, nie nazwie jej `streak`. Zielone
 * o zerowej mocy przesuwa wiersz mapy reguł z sekcji C do sekcji A,
 * nie zmieniając w rzeczywistości nic. Zakaz streaków, masowego importu
 * i sztucznych kont zostaje więc świadomie POZA tym plikiem, z adnotacją
 * „mierzalne tylko przez przegląd człowieka".
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  KRYTERIUM: NIE „AGREGAT KONTRA KOLUMNA", TYLKO CO JEST LICZONE
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Pierwsza wersja kryterium brzmiała „żadne ORDER BY w kodzie feedu
 * nie kluczuje po agregacie". Przegląd sortowań w `app/` pokazał, że taka
 * asercja urodziłaby się CZERWONA na nietkniętym kodzie — i wyglądałoby to
 * na usterkę strażnika, a nie na błąd kryterium:
 *
 *  - `DailyBoard` sortuje propozycje po `MAX(published_at)` z podzapytania.
 *    To JEST agregat i JEST zgodne z regułą, bo agreguje CZAS.
 *  - `SearchQuery` sortuje po `word_similarity(...)`. Algorytmiczne, legalne
 *    i — to jest zdanie, które trzeba przeczytać, zanim się je zakwestionuje —
 *    **trafność wyszukiwania NIE JEST FEEDEM**. Feed to strumień, który
 *    dostaję bez pytania; wyniki wyszukiwania to odpowiedź na moje własne
 *    słowo. Reguła §12 mówi o pierwszym, nie o drugim.
 *
 * Właściwe kryterium: `MAX(published_at)` to czas, `COUNT(obserwujących)`
 * to popularność. Zakazane jest sortowanie po **mierze cudzych reakcji** —
 * wykonaniach, zapisach w zeszytach, obserwujących, komentarzach,
 * polubieniach — a nie po agregatach w ogóle.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  DWA POZIOMY: NIETYKALNE I REJESTR (idiom z
 *  `WrazliweKolumnyPozaMasowymPrzypisaniemTest`)
 * ═══════════════════════════════════════════════════════════════════════
 *
 *  - `NIETYKALNE` — sortowanie, którego klucz jest miarą cudzych reakcji.
 *    Zero wyjątków, rejestr tego nie przyjmuje. To jest sama reguła §12.
 *
 *  - `REJESTR` — sortowania, których klucz NIE jest zwykłą kolumną
 *    ze schematu: aliasy podzapytań, `ROW_NUMBER()`, surowy SQL, domknięcia.
 *    Każde z nich mogłoby liczyć cokolwiek, więc automat ich nie przepuszcza
 *    z urzędu — przepuszcza je człowiek, wpisem z powodem. Gwarancja brzmi
 *    więc: **dodanie sortowania po liczbie wymaga dopisania wiersza
 *    do rejestru**, czyli wejścia drzwiami, a nie oknem. Asercja nie musi
 *    przewidzieć przyszłej gamifikacji.
 *
 * Rejestr jest dokładny w obie strony: sortowanie spoza rejestru oblewa test,
 * a wpis, który nie opisuje żadnego dzisiejszego sortowania, oblewa test jako
 * martwy (`test_rejestr_nie_ma_martwych_wpisow`).
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  PUŁAPKA 2 Z `docs/PULAPKI_TESTOW.md`
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Skan, który nie znajdzie ani jednego pliku, przechodzi — zero trafień jest
 * dla niego sukcesem. Przeniesienie `app/Domain/Feed`, zmiana `glob()` albo
 * literówka w wyrażeniu wyłączyłyby ten plik bez jednego czerwonego przebiegu.
 * Stąd dwie kontrole dodatnie, mierzące dwie różne rzeczy (a trzecią,
 * mutacyjną, trzyma `scripts/kontrole-negatywne-alfa08.py`: sortowanie
 * wpisów w `ZbierzTresciDigestu` po liczbie „Ugotowałem” ma zapalić ten plik):
 *
 *  - `test_skan_naprawde_czyta_kod_feedu` — czy skan widzi pliki, sortowania
 *    i konkretne, znane z nazwy miejsca;
 *  - `test_kryterium_odroznia_czas_od_popularnosci` — czy klasyfikator ma moc,
 *    gdy dostanie sortowanie po popularności. Bez tej drugiej skan mógłby
 *    czytać wszystko i nie umieć niczego odrzucić.
 */
class FeedNieSortujePoMierzeReakcjiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Wywołania, którymi w tym repozytorium ustala się kolejność.
     *
     * `sortBy`/`sortByDesc` są na liście celowo: sortowanie kolekcji w PHP
     * po `$post->liczba_wykonan` łamałoby regułę dokładnie tak samo jak
     * `ORDER BY`, a nie pojawiłoby się w żadnym zapytaniu SQL.
     */
    private const WZORZEC_WYWOLANIA = '/->(orderByRaw|orderByDesc|orderBy|latest|oldest|sortByDesc|sortBy|inRandomOrder|reorder)\s*\(/';

    /**
     * Miara CUDZYCH REAKCJI — poziom NIETYKALNY.
     *
     * Dwa człony, bo samo „count" nie przesądza niczego (`COUNT(*)` bywa
     * warunkiem, nie kluczem), a samo „obserwujący" tym bardziej. Zakazane
     * jest dopiero LICZENIE reakcji.
     */
    private const WZORZEC_REAKCJI = '/(cooked|wykona|obserw|follow|zapis|collection|zeszyt|comment|komentarz|like|polub|reakcj|ulubion|smakowic|odslon|wyswietl|views)/i';

    private const WZORZEC_LICZENIA = '/(?<![a-z])(count|sum|liczb|ile|total|avg|srednia)/i';

    /**
     * Słowa, które same w sobie nazywają ranking popularności — bez drugiego
     * członu, bo tu nie ma czego dopowiadać.
     */
    private const WZORZEC_POPULARNOSCI = '/(popular|ranking|trending|viral|engagement|streak|score|punkty)/i';

    /**
     * Sortowania, których klucz nie jest zwykłą kolumną — świadomie,
     * z powodem. Klucz wpisu to znormalizowany argument wywołania, a nie
     * numer linii: numer rozjeżdża się przy pierwszej zmianie w pliku,
     * a wtedy rejestr zaczyna opisywać nie to miejsce, co opisywał.
     *
     * @var array<string, array<string, string>>
     */
    private const REJESTR = [
        'app/Domain/Digest/OdbiorcyDigestu.php' => [
            "'weekly_digest_sent_at ASC NULLS FIRST'" => 'Kolejka WYSYŁKI tygodniowego listu, nie kolejność treści: '
                .'pierwsze idą osoby, które listu jeszcze nie dostały albo dostały go najdawniej. Klucz to CZAS '
                .'ostatniego listu do adresata — nikt nie trafia wyżej za to, ile reakcji zebrał. Surowy SQL tylko '
                .'dlatego, że Laravel nie ma `NULLS FIRST` w `orderBy()`.',
        ],
        'app/Domain/Digest/ZbierzTresciDigestu.php' => [
            "'digest_odbiorca_id'" => 'Alias `recipes.author_id` / `follows.follower_id` z podzapytania — grupuje wiersze '
                .'paczki PO ADRESACIE listu, żeby rozdać je właściwym osobom. To identyfikator, nie miara.',
            "'digest_row_number'" => 'Alias ROW_NUMBER() OVER (PARTITION BY adresat ORDER BY …) z podzapytania. '
                .'Porządek w oknie wyznacza CZAS: `cooked_events.cooked_at` (wykonania), `follows.created_at` '
                .'(nowi obserwujący), `posts.published_at` (wpisy obserwowanych), z identyfikatorem jako '
                .'rozstrzygnięciem. Przycięcie do `max_pozycji` bierze więc najnowsze, nie najpopularniejsze (D-275).',
        ],
        'app/Domain/Feed/DailyBoard.php' => [
            "'ostatnie.ostatnia_publikacja'" => 'Alias podzapytania MAX(published_at) — agreguje CZAS, nie popularność. '
                .'Komentarz w kodzie tuż nad tym sortowaniem mówi to samo: „Sortujemy po tym, KIEDY ktoś ostatnio '
                .'coś pokazał, nie po tym, ile ma obserwujących." Ten test jest po to, żeby ta reguła przestała być '
                .'komentarzem przy jednym zapytaniu i zaczęła obowiązywać następne, które napisze ktoś inny.',
        ],
        'app/Domain/Feed/DiscoverFeed.php' => [
            "'rotacja.runda'" => 'Alias `row_number() OVER (PARTITION BY posts.author_id ORDER BY posts.published_at DESC, '
                .'posts.id DESC)` z podzapytania — numer wpisu W OBRĘBIE JEDNEJ OSOBY, od najnowszego. To rotacja '
                .'autorów (#1807, D-276): najpierw najnowszy wpis każdej osoby, potem drugi każdej. Liczy własne wpisy '
                .'autora po czasie, nie cudze reakcje — reguła „równość autorów” z listy AGENTS.md §8 (D-275).',
        ],
        'app/Domain/Feed/DailyBoardCandidates.php' => [
            "'latest_publication'" => 'Alias `withMax([\'posts as latest_publication\'], \'published_at\')`, '
                .'czyli MAX(published_at) po WŁASNYCH, publicznie widocznych wpisach osoby — agreguje CZAS, '
                .'nie cudze reakcje. Ta sama miara i ten sam powód co \'ostatnie.ostatnia_publikacja\' '
                .'w app/Domain/Feed/DailyBoard.php, tylko liczona przez withMax zamiast podzapytania. '
                .'Dodatkowo: to jest kolejność LISTY WYBORU dla gospodarza tablicy w panelu moderacji, '
                .'a nie kolejność treści pokazywanej ludziom — ta gałąź przeniosła tu porządkowanie osób '
                .'z DailyBoardController, gdzie było alfabetyczne. Rozstrzyga `orderBy(\'id\')` tuż obok, '
                .'więc przy równym czasie kolejność jest stała, a nie losowa.',
        ],
        'app/Domain/Search/SearchQuery.php' => [
            "'word_similarity(?, recipes.title_search) DESC, similarity(recipes.title_search, ?) DESC', [\$needle, \$needle]" => 'Trafność wyszukiwania przepisów. '
                .'TO NIE JEST FEED — i to jest pierwsza rzecz, którą zakwestionuje następny czytelnik tego rejestru, '
                .'więc stoi tu wprost. Feed to strumień, który dostaję bez pytania; wynik wyszukiwania to odpowiedź '
                .'na słowo, które sam wpisałem. §12 zakazuje układania pierwszego pod popularność, nie zakazuje '
                .'wyszukiwarce być trafną. Liczona jest zbieżność mojej frazy z tytułem, nie cudze reakcje.',
            "'similarity(profiles.display_name_search, ?) DESC', [\$needle]" => 'To samo dla wyszukiwania osób: zbieżność wpisanej frazy '
                .'z nazwą profilu. Zbiór wyników wyznacza LIKE, miara podobieństwa decyduje wyłącznie o kolejności '
                .'odpowiedzi na moje pytanie. Żadnej cudzej reakcji się tu nie liczy.',
        ],
        'app/Domain/Tags/TagCollage.php' => [
            "'tag_row'" => 'Alias ROW_NUMBER() OVER (PARTITION BY tag_id ORDER BY …) z podzapytania — numer kafla w kolażu tagu. '
                .'Porządek wewnątrz okna wyznacza `$order`, czyli czas publikacji; sam `tag_row` niczego nie mierzy, '
                .'tylko numeruje już ustawiony rząd.',
        ],
    ];

    /**
     * Pliki objęte strażnikiem — wyliczane, nie wypisane.
     *
     * Zasięg jest sumą dwóch reguł, żeby nowy plik wszedł pod ochronę sam:
     *  - wszystko w `app/Domain/Feed` (to jest feed z definicji katalogu)
     *    i w `app/Domain/Digest` (tygodniowy list: wpisy obserwowanych,
     *    cudze „Ugotowałem”, nowi obserwujący — D-275),
     *  - każdy inny plik w `app/`, który pyta o `publiclyVisible()`, czyli
     *    o treści pokazywane publicznie.
     * Z obu bierzemy tylko te, które cokolwiek sortują.
     *
     * @return list<string> ścieżki względem korzenia repozytorium
     */
    private function pliki(): array
    {
        $kandydaci = [
            ...(glob(app_path('Domain/Feed/*.php')) ?: []),
            ...(glob(app_path('Domain/Digest/*.php')) ?: []),
        ];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($iterator as $plik) {
            if (! $plik instanceof \SplFileInfo || $plik->getExtension() !== 'php') {
                continue;
            }

            $tresc = (string) file_get_contents($plik->getPathname());

            if (str_contains($tresc, 'publiclyVisible')) {
                $kandydaci[] = $plik->getPathname();
            }
        }

        $wynik = [];

        foreach (array_unique($kandydaci) as $sciezka) {
            $tresc = (string) file_get_contents($sciezka);

            if (preg_match(self::WZORZEC_WYWOLANIA, $tresc) !== 1) {
                continue;
            }

            $wynik[] = str_replace('\\', '/', substr($sciezka, strlen(base_path()) + 1));
        }

        sort($wynik);

        return array_values($wynik);
    }

    /**
     * Sortowania w pliku: metoda, numer linii i znormalizowany argument.
     *
     * Argument wycinamy licząc nawiasy, a nie wyrażeniem regularnym do końca
     * linii — `orderByRaw()` w `SearchQuery` rozciąga się na cztery linie,
     * a `sortBy(fn (...) => ...)` ma nawiasy w środku.
     *
     * @return list<array{metoda: string, linia: int, argument: string}>
     */
    private function sortowania(string $sciezkaWzgledna): array
    {
        $kod = (string) file_get_contents(base_path($sciezkaWzgledna));
        $wynik = [];

        preg_match_all(self::WZORZEC_WYWOLANIA, $kod, $trafienia, PREG_OFFSET_CAPTURE);

        foreach ($trafienia[0] as $i => $trafienie) {
            [$caleTrafienie, $poczatek] = $trafienie;
            $metoda = $trafienia[1][$i][0];
            $otwarcie = $poczatek + strlen($caleTrafienie) - 1;

            $wynik[] = [
                'metoda' => $metoda,
                'linia' => substr_count(substr($kod, 0, $poczatek), "\n") + 1,
                'argument' => $this->normalizuj($this->wytnijArgument($kod, $otwarcie)),
            ];
        }

        return $wynik;
    }

    /** Treść nawiasu otwartego na pozycji `$otwarcie`, z pominięciem nawiasów w łańcuchach. */
    private function wytnijArgument(string $kod, int $otwarcie): string
    {
        $glebokosc = 0;
        $cudzyslow = null;
        $dlugosc = strlen($kod);

        for ($i = $otwarcie; $i < $dlugosc; $i++) {
            $znak = $kod[$i];

            if ($cudzyslow !== null) {
                if ($znak === '\\') {
                    $i++;

                    continue;
                }

                if ($znak === $cudzyslow) {
                    $cudzyslow = null;
                }

                continue;
            }

            if ($znak === "'" || $znak === '"') {
                $cudzyslow = $znak;

                continue;
            }

            if ($znak === '(') {
                $glebokosc++;

                continue;
            }

            if ($znak === ')') {
                $glebokosc--;

                if ($glebokosc === 0) {
                    return substr($kod, $otwarcie + 1, $i - $otwarcie - 1);
                }
            }
        }

        return substr($kod, $otwarcie + 1);
    }

    /** Spacje i przełamania linii do jednej spacji; przecinek na końcu w dół. */
    private function normalizuj(string $argument): string
    {
        return rtrim(trim((string) preg_replace('/\s+/', ' ', $argument)), ',');
    }

    /**
     * Rozstrzygnięcie o jednym sortowaniu: `nietykalne`, `neutralne`
     * albo `wyrazenie`.
     *
     * @param  array<string, list<string>>|null  $kolumny
     */
    private function rozstrzygnij(string $metoda, string $argument, ?array $kolumny = null): string
    {
        if ($this->miaraReakcji($argument)) {
            return 'nietykalne';
        }

        if ($argument === '' && in_array($metoda, ['latest', 'oldest'], true)) {
            // `latest()` bez argumentu to `created_at` — czas, nie miara.
            return 'neutralne';
        }

        if (preg_match('/^([\'"])([A-Za-z0-9_.]+)\1$/', $argument, $dopasowanie) !== 1) {
            return 'wyrazenie';
        }

        return $this->kolumnaSchematu($dopasowanie[2], $kolumny) ? 'neutralne' : 'wyrazenie';
    }

    /** Czy argument liczy cudze reakcje. */
    private function miaraReakcji(string $argument): bool
    {
        if (preg_match(self::WZORZEC_POPULARNOSCI, $argument) === 1) {
            return true;
        }

        return preg_match(self::WZORZEC_REAKCJI, $argument) === 1
            && preg_match(self::WZORZEC_LICZENIA, $argument) === 1;
    }

    /**
     * Czy `kolumna` albo `tabela.kolumna` naprawdę stoi w schemacie bazy.
     *
     * To jest cała różnica między `'published_at'` a `'ostatnie.ostatnia_publikacja'`:
     * oba są zwykłymi łańcuchami w kodzie, ale tylko pierwszy jest kolumną.
     * Drugi jest aliasem podzapytania, czyli wyrażeniem, które może liczyć
     * cokolwiek — i dlatego wymaga wpisu w rejestrze.
     *
     * @param  array<string, list<string>>|null  $kolumny
     */
    private function kolumnaSchematu(string $klucz, ?array $kolumny = null): bool
    {
        $kolumny ??= $this->kolumnySchematu();
        $czesci = explode('.', $klucz);

        if (count($czesci) === 2) {
            return in_array($czesci[1], $kolumny[$czesci[0]] ?? [], true);
        }

        foreach ($kolumny as $lista) {
            if (in_array($klucz, $lista, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, list<string>> tabela => kolumny */
    private function kolumnySchematu(): array
    {
        $kolumny = [];

        foreach (Schema::getTableListing() as $tabela) {
            $krotka = str_contains($tabela, '.') ? substr($tabela, (int) strrpos($tabela, '.') + 1) : $tabela;
            $kolumny[$krotka] = Schema::getColumnListing($krotka);
        }

        return $kolumny;
    }

    /**
     * GŁÓWNY POMIAR: żadne sortowanie publicznych treści nie kluczuje
     * po mierze cudzych reakcji, a każde sortowanie po wyrażeniu ma powód
     * wpisany przez człowieka.
     */
    public function test_zaden_feed_nie_sortuje_po_mierze_cudzych_reakcji(): void
    {
        $kolumny = $this->kolumnySchematu();
        $bledy = [];
        $sprawdzonych = 0;
        $pliki = $this->pliki();

        foreach ($pliki as $plik) {
            $rejestr = self::REJESTR[$plik] ?? [];

            foreach ($this->sortowania($plik) as $sortowanie) {
                $sprawdzonych++;
                $miejsce = "{$plik}:{$sortowanie['linia']}";
                $rozstrzygniecie = $this->rozstrzygnij($sortowanie['metoda'], $sortowanie['argument'], $kolumny);

                if ($rozstrzygniecie === 'nietykalne') {
                    $bledy[] = "{$miejsce} — `{$sortowanie['metoda']}({$sortowanie['argument']})` układa publiczne treści "
                        .'po MIERZE CUDZYCH REAKCJI. To jest feed algorytmiczny, którego zakazuje AGENTS.md §12, '
                        .'i rejestr tego nie przyjmuje. Sortuj po czasie publikacji.';

                    continue;
                }

                if ($rozstrzygniecie === 'neutralne') {
                    continue;
                }

                if (! array_key_exists($sortowanie['argument'], $rejestr)) {
                    $bledy[] = "{$miejsce} — `{$sortowanie['metoda']}({$sortowanie['argument']})` sortuje po wyrażeniu, "
                        .'a nie po kolumnie ze schematu. Automat nie wie, co takie wyrażenie liczy, więc nie przepuszcza '
                        .'go z urzędu. Albo posortuj po kolumnie czasu, albo dopisz do REJESTRU w '
                        .'FeedNieSortujePoMierzeReakcjiTest wpis z powodem, dlaczego to NIE jest miara cudzych reakcji.';

                    continue;
                }

                $this->assertNotSame('', trim($rejestr[$sortowanie['argument']]), "Wpis rejestru {$miejsce} nie ma powodu.");
            }
        }

        $this->assertSame([], $bledy, "Sortowania niezgodne z §12:\n- ".implode("\n- ", $bledy));

        // Zmierzone 20.09.2026 na `main` 534e0a51: zasięg to 10 plików
        // i 38 sortowań (w całym `app/` jest ich 178 — reszta nie dotyczy
        // publicznych treści). Progi są niższe od pomiaru, bo mają łapać
        // ZAWALENIE się skanu, a nie normalną zmianę w kodzie.
        $this->assertGreaterThanOrEqual(8, count($pliki), 'Zasięg strażnika się skurczył — skan przestał widzieć pliki feedu.');
        $this->assertGreaterThanOrEqual(30, $sprawdzonych, 'Sprawdzono zbyt mało sortowań — wyrażenie przestało je łapać.');
    }

    /**
     * REJESTR JEST DOKŁADNY W DRUGĄ STRONĘ.
     *
     * Wpis po sortowaniu, którego już nie ma, udaje, że coś opisuje —
     * i następna osoba czyta go jako opis stanu, którego nie ma.
     */
    public function test_rejestr_nie_ma_martwych_wpisow(): void
    {
        $martwe = [];
        $sprawdzonych = 0;

        foreach (self::REJESTR as $plik => $wpisy) {
            if (! is_file(base_path($plik))) {
                $martwe[] = "{$plik} — takiego pliku już nie ma.";

                continue;
            }

            $argumenty = array_column($this->sortowania($plik), 'argument');

            foreach ($wpisy as $argument => $powod) {
                $sprawdzonych++;

                $this->assertNotSame('', trim($powod), "Wpis {$plik} / {$argument} nie ma powodu.");

                if (! in_array($argument, $argumenty, true)) {
                    $martwe[] = "{$plik} — `{$argument}` nie jest już żadnym sortowaniem w tym pliku. "
                        .'Skasuj wpis: rejestr ma opisywać kod, który istnieje.';
                }
            }
        }

        $this->assertSame([], $martwe, "Martwe wpisy w rejestrze:\n- ".implode("\n- ", $martwe));
        $this->assertGreaterThanOrEqual(5, $sprawdzonych, 'Rejestr się skurczył — albo sortowania wyszły z kodu, albo pętla przestała je czytać.');
    }

    /**
     * KONTROLA DODATNIA 1 (pułapka §2): skan naprawdę czyta kod feedu
     * i naprawdę widzi schemat bazy.
     */
    public function test_skan_naprawde_czyta_kod_feedu(): void
    {
        $pliki = $this->pliki();

        // Kotwice: pliki, o których wiadomo, że istnieją i sortują publiczne
        // treści. Jeśli skan ich nie widzi, nie widzi niczego.
        foreach ([
            'app/Domain/Feed/DailyBoard.php',
            'app/Domain/Feed/DiscoverFeed.php',
            'app/Domain/Feed/FollowingFeed.php',
            'app/Domain/Feed/TagFeed.php',
            'app/Domain/Feed/HeroKolaz.php',
            'app/Domain/Search/SearchQuery.php',
            'app/Domain/Digest/ZbierzTresciDigestu.php',
        ] as $kotwica) {
            $this->assertContains($kotwica, $pliki, "Skan nie widzi {$kotwica} — zasięg strażnika przestał obejmować feed.");
        }

        $sortowania = $this->sortowania('app/Domain/Feed/DailyBoard.php');
        $argumenty = array_column($sortowania, 'argument');

        $this->assertGreaterThanOrEqual(8, count($sortowania), 'W DailyBoard widać mniej sortowań, niż jest — wycinanie argumentu przestało działać.');
        $this->assertContains("'published_at'", $argumenty);
        $this->assertContains(
            "'ostatnie.ostatnia_publikacja'",
            $argumenty,
            'Skan nie widzi sortowania po aliasie podzapytania — czyli nie widzi dokładnie tego miejsca, o które w tej regule chodzi.',
        );

        // Kontrola dodatnia wycinania argumentu: `orderByRaw` w SearchQuery
        // rozciąga się na cztery linie. Gdyby wycinanie kończyło się na końcu
        // linii, argument byłby ucięty i rejestr nigdy by go nie dopasował.
        $this->assertContains(
            "'word_similarity(?, recipes.title_search) DESC, similarity(recipes.title_search, ?) DESC', [\$needle, \$needle]",
            array_column($this->sortowania('app/Domain/Search/SearchQuery.php'), 'argument'),
            'Argument wielolinijkowego orderByRaw został ucięty.',
        );

        // Skan czyta ARGUMENT SORTOWANIA, a nie cały plik. `FollowingFeed`
        // woła `withVisibleCommentCount()` — liczy komentarze — ale nie
        // sortuje po tej liczbie. Gdyby strażnik był gripem po pliku, ten
        // feed oblewałby bez powodu.
        foreach ($this->sortowania('app/Domain/Feed/FollowingFeed.php') as $sortowanie) {
            $this->assertStringNotContainsString('CommentCount', $sortowanie['argument']);
        }

        // Tygodniowy list (D-275): skan widzi sortowanie wpisów obserwowanych
        // po czasie publikacji i sortowanie cudzych „Ugotowałem” po CZASIE
        // ugotowania — `cooked_events.cooked_at` zawiera słowo „cooked”, ale nie
        // liczy niczego, więc nie może wpaść do NIETYKALNYCH.
        $argumentyDigestu = array_column($this->sortowania('app/Domain/Digest/ZbierzTresciDigestu.php'), 'argument');
        $this->assertContains("'published_at'", $argumentyDigestu, 'Skan nie widzi sortowania wpisów w tygodniowym liście.');
        $this->assertContains("'cooked_events.cooked_at'", $argumentyDigestu, 'Skan nie widzi sortowania wykonań w tygodniowym liście.');

        $kolumny = $this->kolumnySchematu();
        $this->assertSame('neutralne', $this->rozstrzygnij('orderByDesc', "'cooked_events.cooked_at'", $kolumny));
        $this->assertGreaterThanOrEqual(20, count($kolumny), 'Skan nie widzi schematu bazy — bez niego każda kolumna wyglądałaby na wyrażenie.');
        $this->assertTrue($this->kolumnaSchematu('posts.published_at', $kolumny));
        $this->assertFalse(
            $this->kolumnaSchematu('ostatnie.ostatnia_publikacja', $kolumny),
            'Alias podzapytania uchodzi za kolumnę — wtedy rejestr byłby pusty, a strażnik ślepy.',
        );
    }

    /**
     * KONTROLA DODATNIA 2: klasyfikator ma moc.
     *
     * Skan może czytać wszystkie pliki świata i nie umieć niczego odrzucić.
     * Tu podajemy mu sortowania, których w repozytorium NIE MA, i sprawdzamy,
     * że rozstrzyga je tak, jak mówi reguła — w obie strony, bo strażnik,
     * który odrzuca wszystko, jest tak samo bezużyteczny jak ten, który
     * nie odrzuca niczego.
     */
    public function test_kryterium_odroznia_czas_od_popularnosci(): void
    {
        $kolumny = $this->kolumnySchematu();

        $przypadki = [
            // miara cudzych reakcji — NIETYKALNE
            ["'obserwujacy_count'", 'nietykalne'],
            ["'cooked_events_count'", 'nietykalne'],
            ["'liczba_zapisow desc'", 'nietykalne'],
            ["'COUNT(comments.id) DESC'", 'nietykalne'],
            ["'popularity_score DESC'", 'nietykalne'],
            ['fn ($post) => $post->wykonania->count()', 'nietykalne'],
            // D-275: odsłony i przyszła reakcja „Smakowicie wygląda” (#1813)
            ["'views_count'", 'nietykalne'],
            ["'liczba_odslon DESC'", 'nietykalne'],
            ["'smakowicie_count'", 'nietykalne'],

            // czas i porządek — wolno
            ["'published_at'", 'neutralne'],
            ["'posts.published_at'", 'neutralne'],
            ["'id'", 'neutralne'],
            ["'position'", 'neutralne'],

            // wyrażenie: automat nie wie, co liczy — wpuszcza je człowiek
            ["'nieistniejaca_kolumna_feedu'", 'wyrazenie'],
            ["'coalesce(published_at, created_at)'", 'wyrazenie'],
        ];

        foreach ($przypadki as $przypadek) {
            [$argument, $oczekiwane] = $przypadek;

            $this->assertSame(
                $oczekiwane,
                $this->rozstrzygnij('orderByDesc', $argument, $kolumny),
                "Sortowanie `{$argument}` zostało rozstrzygnięte inaczej niż `{$oczekiwane}`.",
            );
        }

        // `latest()` bez argumentu to `created_at`, nie brak sortowania.
        $this->assertSame('neutralne', $this->rozstrzygnij('latest', '', $kolumny));
    }
}
