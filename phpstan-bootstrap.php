<?php

declare(strict_types=1);

/**
 * Bootstrap Larastana w git worktree — DRUGA POŁOWA TEJ SAMEJ PUŁAPKI, którą
 * `tests/bootstrap.php` opisuje dla PHPUnita, tym razem w PHPStanie.
 *
 * PROBLEM (zmierzony, nie wydedukowany)
 * `Larastan\Larastan\Properties\ModelPropertyHelper::hasDatabaseProperty()`
 * tworzy PRAWDZIWĄ instancję modelu przez natywny
 * `ReflectionClass::newInstanceWithoutConstructor()`, żeby zapytać ją o nazwę
 * tabeli (`$model->getTable()`) i porównać z kolumnami odczytanymi statycznie
 * z migracji. To wymaga, żeby klasa modelu była NAPRAWDĘ załadowana przez
 * autoloader Composera.
 *
 * W worktree z dowiązanym `vendor/` (`composer.json` ma
 * `optimize-autoloader`, więc `vendor/composer/autoload_classmap.php` to
 * ZAMROŻONA mapa klasa → ścieżka bezwzględna) ten autoloader wskazuje na
 * GŁÓWNY checkout — dokładnie tak samo, jak opisuje `tests/bootstrap.php`.
 * Klasa, która istnieje TYLKO w worktree (czyli każdy nowy model dodany
 * w trakcie pracy nad nim), nie ładuje się wcale: `ReflectionClass` rzuca
 * `ReflectionException`, `hasDatabaseProperty()` łapie go i po cichu zwraca
 * `false`, a PHPStan zgłasza „Access to an undefined property”, mimo że
 * kolumna naprawdę istnieje w migracji.
 *
 * NAJGROŹNIEJSZA CZĘŚĆ: dla modelu, który JUŻ ISTNIEJE w głównym checkoucie
 * (np. `Post`, `Recipe`), błąd się NIE UJAWNIA — autoloader ładuje STARĄ
 * kopię klasy z głównego katalogu, która przypadkiem ma tę samą tabelę,
 * więc analiza „przechodzi”, ale w istocie sprawdza cudzy kod. To jest
 * dokładnie ten sam kształt fałszywej zieloności, przed którym ostrzega
 * `tests/bootstrap.php` dla PHPUnita — tylko tu manifestuje się jako brak
 * błędu zamiast jako błąd, więc jest podstępniejszy.
 *
 * Zmierzone na modelu `App\Models\Tag` (kolumna `status`, dodana w tym
 * worktree): identyczny plik przechodził albo nie WYŁĄCZNIE w zależności od
 * tego, gdzie leżał — nigdy od treści kodu.
 *
 * ROZWIĄZANIE — ta sama idea co w `tests/bootstrap.php`: własny autoloader
 * PSR-4 dla `App\`, wepchnięty PRZED classmap Composera, wskazujący na TEN
 * katalog repozytorium. Świadomie NIE importujemy ani nie kopiujemy
 * `tests/bootstrap.php` (AI_WORKFLOW.md §6 tego zabrania) — to jest OSOBNA,
 * krótsza wersja tej samej idei: PHPStan analizuje modele przez
 * instancjonowanie, nie potrzebuje więc map dla `Database\Factories\`,
 * `Database\Seeders\` ani `Tests\`.
 *
 * NO-OP W GŁÓWNYM KATALOGU I W CI. Tam `vendor/` jest prawdziwym katalogiem,
 * więc `$repoComposera === $repo` i funkcja nic nie robi — zero ryzyka dla
 * pipeline'u, który tego problemu nie ma (CI klonuje repozytorium na świeżo,
 * bez worktree'ów).
 */
(static function (): void {
    $repo = realpath(__DIR__);
    $autoloadComposera = realpath(__DIR__.'/vendor/autoload.php');

    if ($repo === false || $autoloadComposera === false) {
        return;
    }

    $repoComposera = dirname($autoloadComposera, 2);

    if ($repoComposera === $repo) {
        return;
    }

    spl_autoload_register(static function (string $klasa) use ($repo): void {
        if (! str_starts_with($klasa, 'App\\')) {
            return;
        }

        $sciezka = $repo.'/app/'.str_replace('\\', '/', substr($klasa, strlen('App\\'))).'.php';

        if (is_file($sciezka)) {
            require_once $sciezka;
        }
    }, prepend: true);
})();
