<?php

declare(strict_types=1);
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
 *
 * CZEGO TA FUNKCJA NIE OBIECUJE — i to jest ważne, bo poprzednia wersja tego
 * komentarza obiecywała unikalność bezwarunkowo, a jej nie dawała:
 * przypadek 1. NIE JEST unikalny. Dwa katalogi, w których `.git` jest
 * katalogiem, dostaną tę samą bazę "kuking_test" i będą sobie zrzucać
 * schemat. Jest to świadome — główny checkout jest jeden — ale jeśli kiedyś
 * przestanie być jeden, trzeba tu wrócić, a nie dziwić się czerwieni.
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

    $sciezka = str_replace('\\', '/', $sciezka);
    $skrot = substr(hash('sha256', $sciezka), 0, 8);

    // Małe litery, bo bezcudzysłowowy identyfikator i tak jest przez Postgresa
    // składany do małych, a bezpieczniki skryptów (`proba-odtworzenia.sh`,
    // `proba-wycofania.sh`) dopuszczają wyłącznie `[a-z0-9_]`. Wielka litera
    // w nazwie katalogu zatrzymywałaby je na „niedozwolonej nazwie bazy".
    $czytelna = strtolower(kuking_bezpieczny_sufiks_bazy(basename($sciezka), 30));

    return $domyslna.'_kat_'.$czytelna.'_'.$skrot;
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
