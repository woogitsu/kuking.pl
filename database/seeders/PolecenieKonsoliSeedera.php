<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Console\Command;

/**
 * Bezpieczny dostęp do polecenia, które uruchomiło seeder.
 *
 * `Seeder::$command` jest NULL, gdy seeder woła kod (test,
 * `(new TagSeeder)->run()`) zamiast `artisan db:seed`. Laravel opisuje to pole
 * w phpdoc jako zawsze ustawione, więc analiza statyczna uznawała `?->` za
 * zbędne — a bez niego seeder wywołany z testu wywaliłby się na `info()`.
 * Ten typ mówi prawdę: może być `null`.
 */
trait PolecenieKonsoliSeedera
{
    private function konsola(): ?Command
    {
        // `get_object_vars()` z wnętrza klasy widzi też pola chronione i oddaje
        // `mixed` — to, co pole naprawdę zawiera, nie to, co obiecuje phpdoc Laravela.
        $polecenie = get_object_vars($this)['command'] ?? null;

        return $polecenie instanceof Command ? $polecenie : null;
    }
}
