<?php

declare(strict_types=1);

namespace App\Support\Baza;

/**
 * STRAŻNIK NOWYCH MIGRACJI: pilnuje AGENTS.md §6 w migracjach, które
 * powstały PO decyzji właściciela (25.09.2026, issue #989/appeal_id).
 *
 * PO CO
 * Migracja `2026_09_24_120000_add_appeal_id_to_moderation_actions.php`
 * dodaje kolumnę, indeks unikalny i CHECK do istniejącej, gorącej tabeli
 * `moderation_actions` z pominięciem reguł AGENTS.md §6 (`CREATE INDEX
 * CONCURRENTLY`, `ADD CONSTRAINT … NOT VALID` + `VALIDATE CONSTRAINT`).
 * Właściciel zdecydował: ta migracja JEST na produkcji i jej NIE ruszamy.
 * Ten strażnik pilnuje więc tylko migracji NOWSZYCH niż próg poniżej —
 * cofanie się w historii i psucie zielonego CI na przeszłości nie ma sensu.
 *
 * PRÓG
 * `PROG` to znacznik czasu OSTATNIEJ migracji w repozytorium w chwili
 * dodania tego strażnika (25.09.2026: `2026_09_25_100000_*`, dwa pliki).
 * Podniesiony 26.09.2026 do `2026_09_25_200200`: trzy migracje urodzin
 * (`2026_09_25_2000xx`, CHECK na `users` i `dziennik_zgod` bez `NOT VALID`)
 * weszły do main, zanim strażnik istniał — decyzja właściciela: historia,
 * nie przepisujemy scalonej migracji.
 * Migracja z TAKĄ SAMĄ albo WCZEŚNIEJSZĄ datą nie jest sprawdzana (to jest
 * historia, łącznie z migracją appeal_id, `2026_09_24_120000`, wcześniejszą
 * od progu). Migracja z datą PÓŹNIEJSZĄ — jest. Podnoszenie progu wymaga
 * świadomej zmiany tej stałej, nie dzieje się samo.
 *
 * CO SPRAWDZA (i co pomija — nowa tabela reguł nie potrzebuje, AGENTS.md §6)
 *
 *  1. Indeks (`->index()`, `->unique()` w `Schema::table`, albo surowe
 *     `CREATE INDEX` / `CREATE UNIQUE INDEX`) na tabeli, która NIE powstaje
 *     w tej samej migracji — musi iść przez `CREATE INDEX CONCURRENTLY`.
 *  2. Klucz obcy albo CHECK na takiej tabeli — przez Blueprint
 *     (`->constrained()`, `->foreign()`) nigdy nie da się dodać `NOT VALID`,
 *     więc każde takie wywołanie w `Schema::table` na cudzej tabeli
 *     jest naruszeniem. Surowe `ADD CONSTRAINT … CHECK` / `FOREIGN KEY`
 *     muszą nieść `NOT VALID` i mieć gdzieś w pliku odpowiadające
 *     `VALIDATE CONSTRAINT`.
 *  3. Migracja, która używa `CONCURRENTLY` albo pary `NOT VALID` +
 *     `VALIDATE CONSTRAINT`, musi deklarować `public $withinTransaction
 *     = false;` — inaczej `CONCURRENTLY` nie zadziała, a `NOT VALID`
 *     i `VALIDATE` będą się blokować nawzajem do końca wspólnej transakcji.
 *
 * CZEGO NIE SPRAWDZA (bo statycznie nie da się tego zrobić rzetelnie):
 * treści `down()` (D-088 pilnują osobne, nazwane strażniki konkretnych
 * migracji), realnej kolejności instrukcji w SQL, ani tego, czy walidacja
 * NOT VALID dotyczy TEGO SAMEGO ograniczenia, które je założyło (sprawdza
 * tylko, że `VALIDATE CONSTRAINT` gdzieś w pliku w ogóle występuje).
 */
final class StraznikNowychMigracji
{
    /**
     * Znacznik czasu ostatniej migracji w repozytorium w chwili dodania
     * strażnika (25.09.2026). Migracje z TĄ SAMĄ albo wcześniejszą datą
     * (łącznie z appeal_id, `2026_09_24_120000`) są historią i nie są
     * sprawdzane — patrz komentarz klasy.
     */
    public const PROG = '2026_09_25_200200';

    /**
     * Ścieżki (pełne, na dysku) do migracji nowszych niż {@see PROG},
     * posortowane rosnąco po nazwie pliku.
     *
     * @return list<string>
     */
    public static function nowePliki(?string $katalogMigracji = null): array
    {
        $katalog = $katalogMigracji ?? database_path('migrations');

        $pliki = glob($katalog.'/*.php') ?: [];
        sort($pliki);

        return array_values(array_filter(
            $pliki,
            static fn (string $plik): bool => self::znacznikCzasu(basename($plik)) > self::PROG,
        ));
    }

    private static function znacznikCzasu(string $nazwaPliku): string
    {
        // Format Laravela: RRRR_MM_DD_GGMMSS_opis.php — pierwsze 4 podkreślenia.
        $czesci = explode('_', $nazwaPliku, 5);

        return implode('_', array_slice($czesci, 0, 4));
    }

    /**
     * Naruszenia AGENTS.md §6 znalezione w treści migracji. Pusta lista
     * znaczy „migracja przechodzi”. Komunikaty są po polsku i mówią,
     * co poprawić.
     *
     * @return list<string>
     */
    public static function sprawdzTresc(string $tresc): array
    {
        $kod = self::bezKomentarzy($tresc);
        $doAnalizy = self::samoUp($kod);

        $utworzoneTabele = self::utworzoneTabele($doAnalizy);
        $withinTransactionFalse = (bool) preg_match('/\$withinTransaction\s*=\s*false\s*;/', $kod);

        $naruszenia = [];

        $naruszenia = array_merge(
            $naruszenia,
            self::sprawdzBlueprintNaCudzychTabelach($doAnalizy, $utworzoneTabele),
        );

        [$surowe, $uzywaCONCURRENTLY, $uzywaNotValid] = self::sprawdzSurowySql($doAnalizy, $utworzoneTabele);
        $naruszenia = array_merge($naruszenia, $surowe);

        if (($uzywaCONCURRENTLY || $uzywaNotValid) && ! $withinTransactionFalse) {
            $naruszenia[] = 'Migracja używa CONCURRENTLY albo NOT VALID/VALIDATE CONSTRAINT, ale nie deklaruje '
                .'`public $withinTransaction = false;`. Bez tego CONCURRENTLY w ogóle nie zadziała (nie działa '
                .'w transakcji), a NOT VALID i VALIDATE CONSTRAINT będą czekać na tę samą blokadę do końca '
                .'wspólnej transakcji (AGENTS.md §6). Wzorzec: '
                .'2026_09_23_100000_powiaz_status_zgloszenia_z_rozstrzygnieciem.php.';
        }

        if ($uzywaNotValid && ! preg_match('/VALIDATE\s+CONSTRAINT/i', $kod)) {
            $naruszenia[] = 'Ograniczenie dodane z `NOT VALID` nie jest nigdzie w pliku zwalidowane '
                .'(`ALTER TABLE … VALIDATE CONSTRAINT …`). `NOT VALID` bez walidacji zostawia ograniczenie, '
                .'które PostgreSQL wpuszcza, ale nie egzekwuje na starych wierszach (AGENTS.md §6).';
        }

        return $naruszenia;
    }

    /**
     * @param  list<string>  $utworzoneTabele
     * @return list<string>
     */
    private static function sprawdzBlueprintNaCudzychTabelach(string $kod, array $utworzoneTabele): array
    {
        $naruszenia = [];

        foreach (self::blokiSchemaTable($kod) as [$tabela, $body]) {
            if (in_array($tabela, $utworzoneTabele, true)) {
                continue; // Tabela powstaje w tej samej migracji — reguła jej nie dotyczy.
            }

            if (preg_match('/->\s*(constrained|foreign)\s*\(/', $body)) {
                $naruszenia[] = "Klucz obcy dodany przez Blueprint w `Schema::table('{$tabela}', …)` "
                    .'(`->constrained(…)`/`->foreign(…)`). Blueprint nie umie dodać go z `NOT VALID` — na '
                    .'istniejącej, gorącej tabeli użyj surowego `ALTER TABLE … ADD CONSTRAINT … FOREIGN KEY … '
                    .'NOT VALID`, a potem osobno `VALIDATE CONSTRAINT` (AGENTS.md §6). Wzorzec: '
                    .'2026_09_23_100000_powiaz_status_zgloszenia_z_rozstrzygnieciem.php.';
            }

            if (preg_match('/->\s*(index|unique)\s*\(/', $body)) {
                $naruszenia[] = "Indeks dodany przez Blueprint w `Schema::table('{$tabela}', …)` "
                    .'(`->index(…)`/`->unique(…)`). Blueprint nie umie CREATE INDEX CONCURRENTLY — na istniejącej '
                    .'tabeli użyj surowego `CREATE (UNIQUE) INDEX CONCURRENTLY IF NOT EXISTS …` w migracji '
                    .'z `$withinTransaction = false` (AGENTS.md §6).';
            }
        }

        return $naruszenia;
    }

    /**
     * @param  list<string>  $utworzoneTabele
     * @return array{0: list<string>, 1: bool, 2: bool} [naruszenia, użyto CONCURRENTLY gdziekolwiek, użyto NOT VALID gdziekolwiek]
     */
    private static function sprawdzSurowySql(string $kod, array $utworzoneTabele): array
    {
        $naruszenia = [];
        $uzywaCONCURRENTLY = false;
        $uzywaNotValid = false;

        // ALTER TABLE <tabela> ADD CONSTRAINT <nazwa> (CHECK|FOREIGN KEY) …
        // Nazwa ograniczenia bywa złożona przez PHP w osobnej stałej
        // (`'.self::NAZWA.'`), więc między `CONSTRAINT` a `CHECK`/`FOREIGN KEY`
        // może stać coś więcej niż jeden token — stąd `.{0,200}?` zamiast `\S+`.
        if (preg_match_all(
            '/ALTER\s+TABLE\s+([a-zA-Z0-9_]+)\s+ADD\s+CONSTRAINT\s+.{0,200}?(CHECK|FOREIGN\s+KEY)/is',
            $kod,
            $m,
            PREG_OFFSET_CAPTURE,
        )) {
            foreach ($m[0] as $i => [, $offset]) {
                $tabela = $m[1][$i][0];
                $instrukcja = self::instrukcjaOd($kod, $offset);

                if (stripos($instrukcja, 'NOT VALID') !== false) {
                    $uzywaNotValid = true;
                }

                if (in_array($tabela, $utworzoneTabele, true)) {
                    continue; // Tabela powstaje w tej samej migracji.
                }

                if (stripos($instrukcja, 'NOT VALID') === false) {
                    $rodzaj = strtoupper($m[2][$i][0]) === 'CHECK' ? 'CHECK' : 'klucz obcy';
                    $naruszenia[] = "Ograniczenie ({$rodzaj}) dodane do istniejącej tabeli `{$tabela}` bez "
                        .'`NOT VALID` (`ALTER TABLE … ADD CONSTRAINT … NOT VALID`, potem osobno `VALIDATE '
                        .'CONSTRAINT` w osobnej transakcji — AGENTS.md §6). Wzorzec: '
                        .'2026_09_23_100000_powiaz_status_zgloszenia_z_rozstrzygnieciem.php.';
                }
            }
        }

        // CREATE [UNIQUE] INDEX [CONCURRENTLY] [IF NOT EXISTS] <nazwa> ON <tabela>
        if (preg_match_all(
            '/CREATE\s+(?:UNIQUE\s+)?INDEX\s+(CONCURRENTLY\s+)?(?:IF\s+NOT\s+EXISTS\s+)?\S+\s+ON\s+([a-zA-Z0-9_]+)/i',
            $kod,
            $m2,
        )) {
            foreach ($m2[0] as $i => $calosc) {
                $tabela = $m2[2][$i];
                $maCONCURRENTLY = trim($m2[1][$i]) !== '';

                if ($maCONCURRENTLY) {
                    $uzywaCONCURRENTLY = true;
                }

                if (in_array($tabela, $utworzoneTabele, true)) {
                    continue; // Tabela powstaje w tej samej migracji.
                }

                if (! $maCONCURRENTLY) {
                    $naruszenia[] = "Indeks utworzony na istniejącej tabeli `{$tabela}` bez CONCURRENTLY "
                        .'(`CREATE INDEX CONCURRENTLY IF NOT EXISTS …` w migracji z `$withinTransaction = '
                        .'false`, `down()`: `DROP INDEX CONCURRENTLY IF EXISTS` — AGENTS.md §6).';
                }
            }
        }

        return [$naruszenia, $uzywaCONCURRENTLY, $uzywaNotValid];
    }

    /** Podciąg od pozycji dopasowania do pierwszego `;` (jedna instrukcja SQL). */
    private static function instrukcjaOd(string $kod, int $offset): string
    {
        $koniec = strpos($kod, ';', $offset);

        if ($koniec === false) {
            return substr($kod, $offset);
        }

        return substr($kod, $offset, $koniec - $offset + 1);
    }

    /** @return list<string> */
    private static function utworzoneTabele(string $kod): array
    {
        preg_match_all('/Schema::create\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $kod, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Bloki `Schema::table('nazwa', function (Blueprint $table) { … });`,
     * z zawartością nawiasów klamrowych wyciętą przez zliczanie par.
     *
     * @return list<array{0: string, 1: string}> [nazwa tabeli, treść bloku]
     */
    private static function blokiSchemaTable(string $kod): array
    {
        $bloki = [];

        preg_match_all(
            '/Schema::table\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*,\s*function\s*\([^)]*\)(?:\s*:\s*void)?\s*\{/',
            $kod,
            $m,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($m[0] as $i => [$dopasowanie, $offsetPoczatku]) {
            $tabela = $m[1][$i][0];
            $poczatekCiala = $offsetPoczatku + strlen($dopasowanie); // tuż za otwierającym `{`

            $bloki[] = [$tabela, self::wytnijZbalansowane($kod, $poczatekCiala)];
        }

        return $bloki;
    }

    /** Treść od `$start` (tuż po otwierającym `{`) do pasującego zamknięcia. */
    private static function wytnijZbalansowane(string $kod, int $start): string
    {
        $glebokosc = 1;
        $dlugosc = strlen($kod);

        for ($i = $start; $i < $dlugosc; $i++) {
            if ($kod[$i] === '{') {
                $glebokosc++;
            } elseif ($kod[$i] === '}') {
                $glebokosc--;

                if ($glebokosc === 0) {
                    return substr($kod, $start, $i - $start);
                }
            }
        }

        return substr($kod, $start);
    }

    /** Sama treść `up()`: od `function up(` do `function down(` (albo do końca pliku). */
    private static function samoUp(string $kod): string
    {
        $poczatek = stripos($kod, 'function up(');

        if ($poczatek === false) {
            return $kod;
        }

        $koniec = stripos($kod, 'function down(', $poczatek);

        return $koniec === false ? substr($kod, $poczatek) : substr($kod, $poczatek, $koniec - $poczatek);
    }

    /** Usuwa komentarze PHP (liniowe, blokowe i docbloki) tokenizerem — nie regexem. */
    private static function bezKomentarzy(string $tresc): string
    {
        $wynik = '';
        $zOtwarciem = str_starts_with(ltrim($tresc), '<?php') ? $tresc : '<?php '.$tresc;

        foreach (token_get_all($zOtwarciem) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $wynik .= $token[1];
            } else {
                $wynik .= $token;
            }
        }

        return $wynik;
    }
}
