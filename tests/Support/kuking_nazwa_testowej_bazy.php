<?php

declare(strict_types=1);

/**
 * Jedyne źródło reguły nazywania testowej bazy danych (issue #66).
 *
 * Ten plik CELOWO nie zawiera nic poza deklaracją funkcji: żadnego
 * `putenv()`, żadnego `require vendor/autoload.php`. Dzięki temu może go
 * bezpiecznie załadować:
 *
 *  - `tests/bootstrap.php` (PHPUnit) — patrz tam po uzasadnienie samej
 *    reguły i po to, dlaczego nazwa bazy musi być znana, zanim Laravel
 *    zacznie czytać zmienne środowiskowe;
 *  - `.claude/hooks/session-start.sh` — PRZED `composer install`, czyli
 *    zanim `vendor/` w ogóle istnieje. Gdyby ten plik ciągnął za sobą
 *    `vendor/autoload.php` (jak robi to `tests/bootstrap.php`), hook
 *    wywalałby się na każdej świeżo założonej sesji.
 *
 * Wcześniej hook miał WŁASNĄ, niezależną reimplementację tej samej reguły
 * w Bashu — dwa miejsca liczące to samo, które mogły się rozjechać przy
 * pierwszej zmianie reguły w jednym z nich. Ten plik to koniec tego stanu:
 * `tests/bootstrap.php` i hook wołają dokładnie tę samą funkcję.
 *
 * Zmiana reguły (np. o trzeci przypadek — drzewo skopiowane bez `.git`,
 * patrz gałąź `naprawa/baza-proby-per-runtime`) wchodzi TU i obie strony
 * dostają ją za darmo, bez ryzyka rozjazdu.
 */

/**
 * Zwraca nazwę testowej bazy dla danego katalogu repozytorium: "kuking_test"
 * dla głównego checkoutu, "kuking_test_<worktree>" dla `git worktree`.
 */
function kuking_nazwa_testowej_bazy(string $katalogRepo): string
{
    $domyslna = 'kuking_test';

    $wskaznikGit = $katalogRepo.'/.git';

    // Główny checkout: `.git` to katalog ze schematem repo, nie plik
    // wskazujący na worktree — nie ma czego wyliczać.
    if (! is_file($wskaznikGit)) {
        return $domyslna;
    }

    $tresc = file_get_contents($wskaznikGit);
    if ($tresc === false || ! preg_match('/gitdir:\s*(\S+)/', $tresc, $dopasowanie)) {
        return $domyslna;
    }

    // Format wskaźnika worktree: "gitdir: <repo>/.git/worktrees/<nazwa>".
    if (! preg_match('#/\.git/worktrees/([^/]+)/?$#', trim($dopasowanie[1]), $nazwaWorktree)) {
        return $domyslna;
    }

    // Nazwa katalogu worktree bywa dłuższa niż limit identyfikatora
    // Postgresa (63 znaki) po doliczeniu prefiksu "kuking_test_" — a znaki
    // spoza [a-zA-Z0-9_] wymagałyby cudzysłowu w SQL. Obcinamy i czyścimy,
    // zamiast zakładać, że Git zawsze nada bezpieczną nazwę.
    $sufiks = preg_replace('/[^a-zA-Z0-9_]/', '_', $nazwaWorktree[1]);
    $sufiks = substr((string) $sufiks, 0, 50);

    return $domyslna.'_'.$sufiks;
}
