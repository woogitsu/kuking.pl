<?php

declare(strict_types=1);

/**
 * JEDNA reguła nazywania baz pomocniczych Kukinga (issue #66, #736, #920).
 *
 * Ten plik jest CELOWO wolny od Composera i Laravela: wciąga go
 * `tests/bootstrap.php`, ale wciągają go też skrypty powłoki przez
 * `php -r 'require "tests/nazwa-bazy.php"; …'` — w tym
 * `.claude/hooks/session-start.sh`, który zakłada bazę ZANIM istnieje
 * `vendor/`. Gdyby reguła siedziała w `bootstrap.php` (a ten wymaga
 * `vendor/autoload.php`), hook musiałby ją przepisać po raz drugi w bashu —
 * i dokładnie to robił do 19 września. Dwie kopie tej samej reguły rozjechały
 * się przy pierwszej zmianie.
 *
 * ── SKĄD SIĘ BIERZE SAMA NAZWA ─────────────────────────────────────────────
 *
 * Reguła jest TA SAMA, co na `main` po #920 („Licz nazwę testowej bazy
 * z katalogu, nie z nieistniejącego `.git`"), i to jest świadome: gałąź
 * dostraja się do `main`, nie odwrotnie. Trzy przypadki, w tej kolejności —
 * pełne uzasadnienie stoi przy samej funkcji:
 *
 *   1. `.git` jest KATALOGIEM → główny checkout → `kuking_test`,
 *   2. `.git` jest PLIKIEM    → `git worktree`  → `kuking_test_<nazwa>`,
 *   3. `.git` NIE ISTNIEJE    → kopia bez Gita  →
 *      `kuking_test_kat_<katalog do 30 znaków>_<8 znaków SHA-256 ścieżki>`.
 *
 * Przypadek 3. jest tym, który naprawia awarię floty: runtime powstaje
 * rsynkiem z `--exclude '.git'`, więc w katalogu, w którym NAPRAWDĘ chodzą
 * testy, tego pliku nie ma — a każde stanowisko dostawało wcześniej gołe
 * `kuking_test` i wszystkie lądowały w jednej bazie. Objawem nie był jeden
 * zepsuty test, tylko fałszywa czerwień z kontencji, po której każdy musiał
 * najpierw udowodnić, że to nie jego wina.
 *
 * ── CZEGO ŚWIADOMIE NIE WYBRANO I DLACZEGO ─────────────────────────────────
 *
 *  - PID procesu. Dawałby nową, inną bazę przy KAŻDYM uruchomieniu — także
 *    kolejnych w tym samym katalogu, jedno po drugim. To usuwa kolizję kosztem
 *    gwarantowanego zaśmiecania dysku: baza nigdy nie jest ta sama, więc nigdy
 *    nie ma jednej, powtarzalnej rzeczy do posprzątania ani do ponownego
 *    użycia (cache migracji, dane testowe do inspekcji).
 *
 *  - Zmienna z CI (np. GITHUB_RUN_ID). Działa wyłącznie w CI, a w CI przebieg
 *    i tak jest jeden (workflow ma `concurrency`, patrz ci.yml) — czyli
 *    rozwiązywałaby problem tam, gdzie go nie ma, i nie rozwiązywałaby go
 *    lokalnie, gdzie jest to issue.
 *
 *  - Losowy UUID przy starcie. Maksymalna izolacja, ale baza nie ma żadnej
 *    stałej, rozpoznawalnej nazwy — nie da się jej odróżnić od śmiecia bez
 *    dodatkowego rejestru. Nazwa worktree jest czytelna w `psql -l` za darmo
 *    i widać po niej, do czego baza należy.
 *
 *  - SAM skrót pełnej ścieżki repozytorium. Działa, ale jest nieczytelny przy
 *    sprzątaniu (`psql -l` pokazuje ciąg hexów, nie to, o który katalog
 *    chodzi). Tam, gdzie Git nadaje worktree'owi czytelną nazwę, bierzemy ją;
 *    skrót dokładamy tylko tam, gdzie nazwy worktree NIE MA — i wtedy obok
 *    czytelnej nazwy katalogu, nie zamiast niej.
 *
 *  - SAMA nazwa katalogu, bez skrótu. Dwie kopie o tej samej nazwie w różnych
 *    katalogach nadrzędnych (`/home/a/kuking.pl`, `/home/b/kuking.pl`)
 *    dostałyby jedną bazę, czyli dokładnie tę awarię, tylko rzadziej.
 *
 * ── CZEGO TA REGUŁA NIE OBIECUJE ───────────────────────────────────────────
 *
 * Przypadek 1. NIE JEST unikalny: dwa katalogi, w których `.git` jest
 * katalogiem (czyli dwa zwykłe klony), dostaną tę samą bazę `kuking_test`.
 * Jest to świadomy wybór odziedziczony z `main` — główny checkout na maszynie
 * jest jeden, a CI ustawia `DB_DATABASE` jawnie na poziomie joba. Jeśli
 * kiedyś przestanie być jeden, trzeba tu wrócić, a nie dziwić się czerwieni.
 * Jawna zmienna środowiskowa ma ZAWSZE pierwszeństwo (`tests/bootstrap.php`).
 *
 * ── LIMIT 63 BAJTÓW — NIE RUSZAĆ BEZ PRZELICZENIA ──────────────────────────
 *
 * Postgres obcina identyfikator do 63 bajtów BEZ OSTRZEŻENIA, więc dwie za
 * długie nazwy schodzą się po cichu w jedną bazę — czyli wracają dokładnie do
 * błędu, który ten plik naprawia. Najdłuższy przedrostek w repozytorium to
 * `kuking_zrodlo_proby` (`tests/skrypty/proba-odtworzenia.sh`, 19 znaków),
 * a przed sufiksem stoi jeszcze podkreślnik: 19 + 1 + 43 = 63. Stąd domyślne
 * 43 w `kuking_bezpieczny_sufiks_bazy()`, a nie 50, które stało tu kiedyś
 * i dawało 70 znaków, czyli ciche obcięcie.
 */

/**
 * Zwraca nazwę testowej bazy dla danego katalogu repozytorium. Trzy przypadki,
 * w tej kolejności:
 *
 *  1. `.git` jest KATALOGIEM → główny checkout → "kuking_test" (bez sufiksu).
 *     Na jednej maszynie główny checkout jest jeden, a CI i tak ustawia
 *     `DB_DATABASE` wprost, więc ta nazwa nie koliduje z niczym.
 *
 *  2. `.git` jest PLIKIEM wskazującym na `.git/worktrees/<nazwa>` →
 *     "kuking_test_<nazwa>". Git sam pilnuje, żeby te nazwy były różne,
 *     a przy tym są czytelne w `psql -l`.
 *
 *  3. `.git` NIE ISTNIEJE albo nie da się z niego odczytać nazwy worktree →
 *     "kuking_test_kat_<katalog>_<8 znaków skrótu pełnej ścieżki>".
 *
 * DLACZEGO PRZYPADEK 3. LICZY SIĘ Z KATALOGU, A NIE Z `.git`
 * Bo `.git` w runtime NIE ISTNIEJE. Runtime floty powstaje rsynkiem
 * z `--exclude '.git'` (`_wspolne/przygotuj-runtime.sh`), a kod rozpakowany
 * z archiwum czy z obrazu kontenera też go nie ma. Katalog repozytorium
 * natomiast istnieje ZAWSZE — jest tym, z czego uruchamiane są testy, więc
 * nie da się go „zapomnieć skopiować". To jedyne źródło, które w runtime
 * jest pewne. Pełna ścieżka jest przy tym na jednej maszynie unikalna
 * z definicji systemu plików, czyli dwa stanowiska nie mogą dostać tej samej
 * nazwy, choćby katalogi nazywały się tak samo w różnych miejscach.
 *
 * Skrót jest ośmioznakowy i stoi OBOK czytelnej nazwy katalogu, nie zamiast
 * niej: sama nazwa katalogu bywa powtarzalna (dwa `…/kuking.pl` w różnych
 * drzewach), a sam skrót jest nieczytelny przy sprzątaniu baz.
 */
function kuking_nazwa_testowej_bazy(string $katalogRepo): string
{
    $domyslna = 'kuking_test';

    $wskaznikGit = $katalogRepo.'/.git';

    // Główny checkout: `.git` to katalog ze schematem repo, nie plik
    // wskazujący na worktree — nie ma czego wyliczać.
    if (is_dir($wskaznikGit)) {
        return $domyslna;
    }

    if (is_file($wskaznikGit)) {
        $tresc = file_get_contents($wskaznikGit);

        if ($tresc !== false && preg_match('/gitdir:\s*(\S+)/', $tresc, $dopasowanie)
            // Format wskaźnika worktree: "gitdir: <repo>/.git/worktrees/<nazwa>".
            && preg_match('#/\.git/worktrees/([^/]+)/?$#', trim($dopasowanie[1]), $nazwaWorktree)
        ) {
            return $domyslna.'_'.kuking_bezpieczny_sufiks_bazy($nazwaWorktree[1]);
        }
    }

    // Przypadek 3.: kopia repozytorium bez `.git` — runtime floty, archiwum,
    // obraz kontenera. Nazwa musi wynikać z katalogu, bo nic innego tu nie ma.
    $sciezka = realpath($katalogRepo);

    if ($sciezka === false) {
        // Katalogu nie ma wcale. Nie ma z czego liczyć nazwy, a zgadywanie
        // byłoby gorsze od domyślnej: i tak nie ma czego uruchomić.
        return $domyslna;
    }

    // Normalizujemy separator i końcowy ukośnik, żeby „/repo" i „/repo/" nie
    // dawały dwóch różnych baz dla jednego katalogu.
    $sciezka = rtrim(str_replace('\\', '/', $sciezka), '/');
    $skrot = substr(hash('sha256', $sciezka), 0, 8);

    // Małe litery, bo bezcudzysłowowy identyfikator i tak jest przez Postgresa
    // składany do małych, a bezpieczniki skryptów (`proba-odtworzenia.sh`,
    // `proba-wycofania.sh`) dopuszczają wyłącznie `[a-z0-9_]`. Wielka litera
    // w nazwie katalogu zatrzymywałaby je na „niedozwolonej nazwie bazy".
    $czytelna = strtolower(kuking_bezpieczny_sufiks_bazy(basename($sciezka), 30));

    return $domyslna.'_kat_'.$czytelna.'_'.$skrot;
}

/**
 * Zwraca nazwę bazy dla grupy testów `dwa-polaczenia` (D-105): "kuking_race"
 * w głównym checkoucie, "kuking_race_<sufiks>" wszędzie indziej.
 *
 * Ta grupa NIE MOŻE chodzić na `kuking_test*`: testy na dwóch połączeniach
 * zatwierdzają dane naprawdę (bez `RefreshDatabase`), więc żyją obok zwykłego
 * przebiegu — a zwykły przebieg na tej samej bazie zrzuciłby im schemat
 * w trakcie działania.
 *
 * Sufiks liczy `kuking_nazwa_testowej_bazy()` — świadomie TA SAMA metoda,
 * żeby nie było w repozytorium dwóch reguł nazywania baz, które mogą się
 * rozjechać. Zmiana tamtej funkcji przenosi się tutaj sama.
 */
function kuking_nazwa_bazy_wyscigow(string $katalogRepo): string
{
    return 'kuking_race'.substr(kuking_nazwa_testowej_bazy($katalogRepo), strlen('kuking_test'));
}

/**
 * Zwraca nazwę bazy POMIAROWEJ dla próby wycofania migracji
 * (`scripts/proba-wycofania.sh`).
 *
 * Prefiks jest inny niż `kuking_*` i to jest jego jedyne zadanie: skrypt
 * próby wycofania KASUJE swoją bazę i zrzuca w niej schemat do zera, czyli
 * robi dokładnie to, czego nie wolno zrobić nigdzie indziej. Jego bezpiecznik
 * przepuszcza wyłącznie nazwy pasujące do `proba_wycofania*`, więc `kuking`,
 * `kuking_test*`, `kuking_race*` ani `railway` nie wpadną do niego nawet przy
 * literówce: to nie jest różnica jednego znaku.
 *
 * Sufiks liczy `kuking_nazwa_testowej_bazy()` — świadomie TA SAMA metoda, co
 * przy bazie testowej i wyścigowej, żeby nie było w repozytorium trzech reguł
 * nazywania baz, które rozjadą się przy pierwszej zmianie.
 */
function kuking_nazwa_bazy_wycofania(string $katalogRepo): string
{
    return 'proba_wycofania'.substr(kuking_nazwa_testowej_bazy($katalogRepo), strlen('kuking_test'));
}

/**
 * Sprowadza dowolny tekst do bezpiecznego fragmentu identyfikatora Postgresa.
 *
 * Znaki spoza [a-zA-Z0-9_] wymagałyby cudzysłowu w SQL, a długość ma znaczenie
 * większe, niż widać: Postgres obcina identyfikator do 63 bajtów BEZ
 * OSTRZEŻENIA, więc dwie za długie nazwy potrafią po cichu zejść się w jedną
 * bazę — czyli wrócić do dokładnie tego błędu, który ten plik naprawia.
 * Najdłuższy przedrostek w repozytorium to "kuking_zrodlo_proby"
 * (`tests/skrypty/proba-odtworzenia.sh`, 19 znaków), a przed sufiksem stoi
 * jeszcze podkreślnik: 19 + 1 + 43 = 63. Stąd domyślne 43, a nie 50, które
 * stało tu wcześniej i dawało 70 znaków, czyli ciche obcięcie.
 */
function kuking_bezpieczny_sufiks_bazy(string $tekst, int $limit = 43): string
{
    $sufiks = preg_replace('/[^a-zA-Z0-9_]/', '_', $tekst);

    return substr((string) $sufiks, 0, $limit);
}

/**
 * Katalog rejestru żywych kopii roboczych.
 *
 * PO CO REJESTR: `scripts/cleanup-test-dbs.sh` musi umieć odróżnić bazę po
 * skasowanej kopii roboczej od bazy kopii, w której ktoś właśnie pracuje.
 * Ze samej nazwy `kuking_test_kat_<katalog>_<skrót>` nie da się odtworzyć
 * ścieżki — skrót jest jednokierunkowy. Dlatego każdy przebieg testów
 * zostawia tu plik o nazwie bazy, a w nim ścieżkę swojej kopii roboczej.
 * Sprzątacz kasuje WYŁĄCZNIE bazy, dla których taki wpis istnieje, a zapisana
 * w nim ścieżka już nie istnieje na dysku. Bazy bez wpisu zostawia — „nie
 * wiem" znaczy „zostawiam", bo pomyłka w drugą stronę jest nieodwracalna dla
 * kogoś, kto akurat pracuje.
 */
function kuking_katalog_rejestru_baz(): string
{
    $jawny = getenv('KUKING_REJESTR_BAZ');

    if (is_string($jawny) && $jawny !== '') {
        return rtrim($jawny, '/');
    }

    $dom = getenv('HOME');

    if (! is_string($dom) || $dom === '') {
        $dom = sys_get_temp_dir();
    }

    return rtrim($dom, '/').'/.kuking-bazy-testowe';
}

/**
 * Odnotowuje, że baza `$nazwaBazy` należy do kopii roboczej `$katalogRepo`.
 *
 * Celowo bez rzucania wyjątków: rejestr jest udogodnieniem dla sprzątacza,
 * a nie warunkiem uruchomienia testów. Brak prawa zapisu do katalogu
 * domowego ma oznaczać „sprzątacz nie ruszy tej bazy", a nie „testy nie
 * chodzą". `@` jest tu świadome — to jedyne miejsce w tym pliku, gdzie
 * błąd wejścia/wyjścia jest bez znaczenia.
 */
function kuking_zapisz_rejestr_bazy(string $nazwaBazy, string $katalogRepo): void
{
    $katalog = kuking_katalog_rejestru_baz();

    if (! is_dir($katalog) && ! @mkdir($katalog, 0o700, true) && ! is_dir($katalog)) {
        return;
    }

    $sciezka = realpath($katalogRepo);

    if ($sciezka === false) {
        $sciezka = $katalogRepo;
    }

    @file_put_contents(
        $katalog.'/'.$nazwaBazy,
        rtrim(str_replace('\\', '/', $sciezka), '/')."\n",
    );
}
