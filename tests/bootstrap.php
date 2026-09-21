<?php

declare(strict_types=1);

/**
 * Bootstrap PHPUnit (issue #66, #736): wylicza nazwę testowej bazy danych,
 * zanim Laravel w ogóle zacznie czytać zmienne środowiskowe.
 *
 * Co się działo bez tego: `phpunit.xml` ustawiał DB_DATABASE na sztywno
 * "kuking_test". Kiedy dwa przebiegi `php artisan test` chodziły naraz na
 * tej samej bazie, `RefreshDatabase` jednego z nich kasowało schemat
 * w trakcie działania drugiego — testy padały z "relation ... does not
 * exist", mimo że w kodzie nie było żadnego błędu.
 *
 * SAMA REGUŁA NAZYWANIA SIEDZI W `tests/nazwa-bazy.php` — tam jest też
 * pełne uzasadnienie, co ją różnicuje i czego świadomie nie wybrano. Jest
 * osobnym plikiem, bo czytają ją także skrypty powłoki, które działają bez
 * `vendor/` (m.in. `.claude/hooks/session-start.sh`).
 *
 * UWAGA na kolejność: `putenv()` musi paść PRZED `vendor/autoload.php`,
 * bo dopiero tamten wciąga Dotenva Laravela — a Dotenv jest niemutowalny
 * i nie nadpisze zmiennej, która już jest w środowisku. Stąd też druga
 * strona tej samej monety: ręczne `DB_DATABASE=… php artisan test` (shell,
 * CI) ma ZAWSZE pierwszeństwo, bo ten plik nie rusza zmiennej już ustawionej.
 *
 * Skutek uboczny, zamierzony: nazwa bazy NIE pochodzi z `.env` kopii
 * roboczej. Gdyby pochodziła, dwie kopie zrobione przez `cp -a` miałyby tę
 * samą nazwę bazy aż do chwili, w której ktoś ręcznie poprawi `.env` —
 * czyli izolacja zależałaby od pamięci człowieka. Tu zależy od worktree,
 * a tam, gdzie `.git` nie ma wcale, od ścieżki katalogu — której nie da się
 * zapomnieć zmienić, bo bez niej nie byłoby czego uruchomić.
 */
require __DIR__.'/nazwa-bazy.php';

$kuking_baza_testowa = getenv('DB_DATABASE');

if ($kuking_baza_testowa === false || $kuking_baza_testowa === '') {
    $kuking_baza_testowa = kuking_nazwa_testowej_bazy(__DIR__.'/..');
    putenv('DB_DATABASE='.$kuking_baza_testowa);
}

// Wpis do rejestru mówi `scripts/cleanup-test-dbs.sh`, do której kopii
// roboczej należy ta baza. Bez wpisu sprzątacz uznaje bazę za „nie wiem,
// czyja" i jej NIE rusza — patrz `kuking_katalog_rejestru_baz()`.
kuking_zapisz_rejestr_bazy($kuking_baza_testowa, __DIR__.'/..');

unset($kuking_baza_testowa);

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
