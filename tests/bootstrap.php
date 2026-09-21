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
require __DIR__.'/Support/kuking_nazwa_testowej_bazy.php';

if (getenv('DB_DATABASE') === false || getenv('DB_DATABASE') === '') {
    putenv('DB_DATABASE='.kuking_nazwa_testowej_bazy(__DIR__.'/..'));
}

require __DIR__.'/../vendor/autoload.php';

/*
 * KLASY TEŻ MUSZĄ POCHODZIĆ Z TEGO KATALOGU, NIE TYLKO TRASY.
 *
 * `AGENTS.md` §10 mówi, żeby w worktree uruchamiać testy z `APP_BASE_PATH`,
 * „inaczej Laravel załaduje trasy i klasy z głównego katalogu, a testy będą
 * fałszywie zielone". Pierwsza połowa tego zdania działa. DRUGA NIE DZIAŁAŁA
 * i to jest dokładnie ten rodzaj obietnicy bez pokrycia w kodzie, przed
 * którym ostrzega cała reszta tego repozytorium.
 *
 * Powód: `vendor/` w worktree jest DOWIĄZANIEM do głównego katalogu, a PHP
 * rozwija dowiązania w `__DIR__`. Autoloader Composera liczy więc swój
 * `$baseDir` od PRAWDZIWEGO położenia pliku, czyli od głównego checkoutu —
 * i mapuje `App\` na `app/` głównego katalogu, niezależnie od tego, skąd
 * uruchomiono testy i co stoi w `APP_BASE_PATH` (ten zmienia ścieżkę bazową
 * Laravela, nie autoloadera). `composer.json` ma przy tym
 * `optimize-autoloader`, więc jest to zamrożona mapa ścieżek bezwzględnych.
 *
 * Skutek był najgorszy z możliwych: PHPUnit ładował PLIKI TESTÓW z worktree
 * (bo bierze je ze ścieżki), ale KLASY APLIKACJI z głównego katalogu. Testy
 * przechodziły, sprawdzając cudzy kod. Agent widział 891 zielonych testów
 * i nie miał żadnego sygnału, że jego zmiany w `app/` nie zostały nawet raz
 * wykonane.
 *
 * Rejestrujemy więc własny autoloader PRZED composerowym, z mapami PSR-4
 * przeczytanymi z `composer.json` tego katalogu. W zwykłym checkoucie ta
 * funkcja nie robi nic (warunek niżej), więc CI i główny katalog zachowują
 * się bez zmian. Zero zmian w `vendor/`, czyli zero ryzyka dla innych
 * agentów pracujących równolegle.
 */
kuking_klasy_z_tego_katalogu(__DIR__.'/..');

/**
 * Rejestruje autoloader PSR-4 wskazujący na TEN katalog repozytorium.
 *
 * No-op wszędzie tam, gdzie `vendor/` leży naprawdę w tym katalogu — czyli
 * w głównym checkoucie i w CI.
 */
function kuking_klasy_z_tego_katalogu(string $katalogRepo): void
{
    $repo = realpath($katalogRepo);
    $autoloadComposera = realpath($katalogRepo.'/vendor/autoload.php');

    if ($repo === false || $autoloadComposera === false) {
        return;
    }

    // Katalog, od którego autoloader Composera liczy swoje ścieżki.
    $repoComposera = dirname($autoloadComposera, 2);

    if ($repoComposera === $repo) {
        return;
    }

    $composerJson = @file_get_contents($repo.'/composer.json');

    if ($composerJson === false) {
        return;
    }

    /** @var array{autoload?: array{'psr-4'?: array<string, string>}, 'autoload-dev'?: array{'psr-4'?: array<string, string>}} $manifest */
    $manifest = json_decode($composerJson, true) ?: [];

    $mapy = array_merge(
        $manifest['autoload']['psr-4'] ?? [],
        $manifest['autoload-dev']['psr-4'] ?? [],
    );

    if ($mapy === []) {
        return;
    }

    spl_autoload_register(function (string $klasa) use ($repo, $mapy): void {
        foreach ($mapy as $prefiks => $katalog) {
            if (! str_starts_with($klasa, $prefiks)) {
                continue;
            }

            $sciezka = $repo.'/'.trim($katalog, '/').'/'
                .str_replace('\\', '/', substr($klasa, strlen($prefiks))).'.php';

            if (is_file($sciezka)) {
                require_once $sciezka;

                return;
            }
        }
    }, prepend: true);
}

/**
 * Zwraca nazwę bazy dla grupy testów `dwa-polaczenia` (D-105): "kuking_race"
 * dla głównego checkoutu, "kuking_race_<worktree>" dla `git worktree`.
 *
 * Ta grupa NIE MOŻE chodzić na `kuking_test*` i nie jest to ostrożność na
 * wyrost. Testy na dwóch połączeniach zatwierdzają dane naprawdę (bez
 * `RefreshDatabase`), więc żyją obok zwykłego przebiegu — a zwykły przebieg
 * na tej samej bazie zrzuciłby im schemat w trakcie działania. To dokładnie
 * issue #66, tylko z drugiej strony: tam kolidowały dwa zwykłe przebiegi,
 * tutaj kolidowałby zwykły z wyścigowym.
 *
 * Sufiks liczy `kuking_nazwa_testowej_bazy()` — świadomie TA SAMA metoda,
 * żeby nie było w repozytorium dwóch reguł nazywania baz, które mogą się
 * rozjechać. Zmiana tamtej funkcji przenosi się tutaj sama.
 *
 * Ta funkcja NICZEGO nie ustawia w środowisku: `DB_DATABASE` dla zwykłego
 * przebiegu liczy się wyżej i pozostaje nietknięte. Nazwę bazy wyścigów
 * bierze `Tests\Dwa\TestDwochPolaczen::setUp()` oraz
 * `scripts/testy-dwa-polaczenia.sh`.
 */
function kuking_nazwa_bazy_wyscigow(string $katalogRepo): string
{
    return 'kuking_race'.substr(kuking_nazwa_testowej_bazy($katalogRepo), strlen('kuking_test'));
}

/**
 * Zwraca nazwę bazy POMIAROWEJ dla próby wycofania migracji
 * (`scripts/proba-wycofania.sh`): "proba_wycofania" w głównym checkoucie,
 * "proba_wycofania_<worktree>" w `git worktree`.
 *
 * Prefiks jest inny niż `kuking_*` i to jest jego jedyne zadanie. Skrypt
 * próby wycofania KASUJE swoją bazę i zrzuca w niej schemat do zera — czyli
 * robi dokładnie to, czego nie wolno zrobić nigdzie indziej. Jego bezpiecznik
 * przepuszcza wyłącznie nazwy pasujące do `proba_wycofania*`, więc `kuking`,
 * `kuking_test*`, `kuking_race*` ani `railway` nie wpadną do niego nawet
 * przy literówce: to nie jest różnica jednego znaku.
 *
 * Sufiks liczy `kuking_nazwa_testowej_bazy()` — świadomie TA SAMA metoda, co
 * przy bazie testowej i wyścigowej, żeby nie było w repozytorium trzech reguł
 * nazywania baz, które rozjadą się przy pierwszej zmianie.
 */
function kuking_nazwa_bazy_wycofania(string $katalogRepo): string
{
    return 'proba_wycofania'.substr(kuking_nazwa_testowej_bazy($katalogRepo), strlen('kuking_test'));
}

// `kuking_nazwa_testowej_bazy()` żyje teraz w `tests/Support/kuking_nazwa_testowej_bazy.php`
// (wymagane na górze tego pliku) — to jedyne źródło tej reguły, współdzielone
// z `.claude/hooks/session-start.sh`. Nie dopisuj tu drugiej definicji.
