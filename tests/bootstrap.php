<?php

declare(strict_types=1);

/**
 * Bootstrap PHPUnit (issue #66): wylicza nazwę testowej bazy danych, zanim
 * Laravel w ogóle zacznie czytać zmienne środowiskowe.
 *
 * Co się działo bez tego: `phpunit.xml` ustawiał DB_DATABASE na sztywno
 * "kuking_test". Kiedy dwa przebiegi `php artisan test` chodziły naraz na
 * tej samej bazie (np. główny katalog + worktree agenta), `RefreshDatabase`
 * jednego z nich kasowało schemat w trakcie działania drugiego — testy
 * padały z "relation ... does not exist", mimo że w kodzie nie było
 * żadnego błędu. Dokładnie to zdarzyło się przy pracy nad tym repozytorium.
 *
 * Rozwiązanie: nazwa bazy zależy od KATALOGU ROBOCZEGO — a konkretnie od
 * tego, czy repozytorium jest głównym checkoutem, czy osobnym
 * `git worktree`. Każdy worktree ma własny, stały katalog na dysku (i Git
 * sam nadaje mu unikalną nazwę w `.git/worktrees/<nazwa>`), więc dwa
 * równoległe przebiegi w różnych katalogach naturalnie trafiają w różne
 * bazy — bez ustawiania czegokolwiek ręcznie. Domyślne `php artisan test`
 * w głównym katalogu dalej idzie na "kuking_test", tak jak dziś.
 *
 * Ręczne ustawienie DB_DATABASE (shell, CI) ma ZAWSZE pierwszeństwo — ten
 * plik nie nadpisuje zmiennej, która już jest ustawiona (patrz job `test`
 * w .github/workflows/ci.yml, który ustawia DB_DATABASE=kuking_test wprost
 * na poziomie joba — tam przebieg jest jeden, więc nic się tu nie zmienia).
 *
 * Czego świadomie NIE wybrano i dlaczego:
 *
 *  - PID procesu. Dawałby nową, inną bazę przy KAŻDYM uruchomieniu — także
 *    kolejnych w tym samym katalogu, jedno po drugim. To usuwa kolizję
 *    kosztem gwarantowanego zaśmiecania dysku: baza nigdy nie jest ta sama,
 *    więc nigdy nie ma jednej, powtarzalnej rzeczy do posprzątania ani do
 *    ponownego użycia (cache migracji, dane testowe do inspekcji).
 *
 *  - Zmienna z CI (np. GITHUB_RUN_ID). Działa wyłącznie w CI, a w CI
 *    przebieg i tak jest jeden (workflow ma `concurrency`, patrz ci.yml) —
 *    czyli rozwiązywałaby problem tam, gdzie go nie ma, i nie rozwiązywałaby
 *    go lokalnie, gdzie jest to issue.
 *
 *  - Losowy UUID przy starcie. Maksymalna izolacja, ale baza nie ma żadnej
 *    stałej, rozpoznawalnej nazwy — nie da się jej odróżnić od śmiecia bez
 *    dodatkowego rejestru. Nazwa worktree jest czytelna w `psql -l` za darmo
 *    i widać po niej, do czego baza należy.
 *
 *  - Hash pełnej ścieżki repozytorium. Działałby równie dobrze jak nazwa
 *    worktree, ale jest nieczytelny przy sprzątaniu (`psql -l` pokazuje
 *    ciąg hexów, nie to, o który worktree chodzi). Git już nadaje worktree'om
 *    czytelne, unikalne nazwy — nie ma sensu liczyć własnego hashu obok.
 *
 * Sprzątanie: `scripts/cleanup-test-dbs.sh` usuwa bazy `kuking_test_*`,
 * których worktree już nie istnieje na dysku (czyli został usunięty przez
 * `git worktree remove`, a baza po nim została).
 */
if (getenv('DB_DATABASE') === false || getenv('DB_DATABASE') === '') {
    putenv('DB_DATABASE='.kuking_nazwa_testowej_bazy(__DIR__.'/..'));
}

require __DIR__.'/../vendor/autoload.php';

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
