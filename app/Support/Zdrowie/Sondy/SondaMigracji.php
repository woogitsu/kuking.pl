<?php

declare(strict_types=1);

namespace App\Support\Zdrowie\Sondy;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sonda;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;

/**
 * Sonda `migrations` endpointu `/health` (issue #2212) — logika wyjęta z
 * `HealthController` bez zmiany zachowania; kontroler składa z sond odpowiedź.
 */
final class SondaMigracji implements Sonda
{
    public function nazwa(): string
    {
        return 'migrations';
    }

    public function powodDomyslny(): string
    {
        return Powody::POWOD_BAZA;
    }

    /**
     * Migracje OCZEKUJĄCE, nie tylko tabela pusta (issue #1844).
     *
     * Do 26 września 2026 kontrola sprawdzała wyłącznie
     * `DB::table('migrations')->count() === 0` — czyli WYŁĄCZNIE „czy deploy
     * w ogóle dotknął migracji kiedykolwiek". Baza z jedną wykonaną migracją
     * sprzed miesięcy przechodziła ten warunek, nawet gdy obraz aplikacji
     * niesie dziś dziesięć nowych plików migracji, których NIKT nie wykonał
     * — częściowe wdrożenie, przerwane `php artisan migrate` albo replika,
     * która nie zdążyła dogonić reszty. `/health` melduje `ok`, Railway
     * kieruje na nią ruch, a pierwsze żądanie czytające nową kolumnę albo
     * tabelę kończy się 500.
     *
     * Dziś porównujemy PLIKI migracji z WIERSZAMI w tabeli `migrations` —
     * dokładnie to, co widzi `php artisan migrate:status`, bez uruchamiania
     * czegokolwiek. `Migrator::getMigrationFiles()` tylko czyta katalog
     * (żadnego zapytania), więc jedyny SQL w tej kontroli to ten sam
     * `SELECT` co wcześniej. Ścieżki bierzemy jak robi to
     * `migrate`/`migrate:status`: własne katalogi `$migrator->paths()`
     * (np. z pakietów) plus domyślny `database/migrations`.
     *
     * Pusta tabela WCIĄŻ jest awarią (zbiór wykonanych migracji jest wtedy
     * pusty, więc KAŻDY plik migracji wypada jako oczekujący) — ten sam
     * powód, ta sama etykieta, zerowa zmiana zachowania dla dotychczasowego
     * przypadku. Nowość to wykrycie migracji brakujących MIMO niepustej
     * tabeli.
     *
     * Publicznie zostaje wyłącznie kod `brak_migracji` (patrz `check()`) —
     * nazwy plików migracji (które ujawniałyby kształt schematu) nie
     * pojawiają się nigdzie w odpowiedzi HTTP, tylko w komunikacie
     * wyjątku, który trafia WYŁĄCZNIE do `Log::error` w `check()`.
     */
    public function sprawdz(): void
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        $sciezki = array_merge($migrator->paths(), [database_path('migrations')]);
        $pliki = $migrator->getMigrationFiles($sciezki);

        $wykonane = DB::table('migrations')->pluck('migration')->all();
        $oczekujace = array_diff(array_keys($pliki), $wykonane);

        if ($oczekujace !== []) {
            throw new KontrolaZdrowiaNieprzeszla(
                Powody::POWOD_BRAK_MIGRACJI,
                'Oczekujące migracje względem aktualnego obrazu aplikacji: '.implode(', ', $oczekujace).'.',
            );
        }
    }
}
