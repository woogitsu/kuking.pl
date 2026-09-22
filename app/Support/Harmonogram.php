<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use RuntimeException;

/**
 * Bezpieczne rejestrowanie i uruchamianie komend Artisan w harmonogramie.
 *
 * DLACZEGO TA KLASA ISTNIEJE (issue #835, audyt C1):
 * 1. `docker/php.ini` wyłącza `proc_open` (hardening, AGENTS.md zabrania go osłabiać).
 *    Dlatego nie wolno używać standardowego `Schedule::command()`, które odpala
 *    proces przez Symfony Process — na produkcji kładzie to cały kontener (rola `all`).
 * 2. Zastąpienie go gołym `Schedule::call(fn () => Artisan::call(...))` miało jednak
 *    krytyczną lukę: `Artisan::call()` zwraca kod wyjścia (int: 0 sukces, != 0 błąd),
 *    a CallbackEvent w schedulerze Laravela traktuje domknięcie bez rzuconego wyjątku
 *    jako pełen sukces! W efekcie zadania cykliczne mogły padać co noc, a system
 *    i monitoring milczały.
 * 3. Ta klasa ujednolica wzorzec RAZ:
 *    - wykonuje komendę w tym samym procesie (bez proc_open),
 *    - weryfikuje kod wyjścia i rzuca RuntimeException przy kodzie != 0,
 *    - dzięki temu scheduler rejestruje porażkę, loguje błąd i odpala alerty.
 */
final class Harmonogram
{
    /**
     * Rejestruje komendę Artisan w harmonogramie bez proc_open,
     * z twardą kontrolą kodu wyjścia.
     *
     * @param  array<string, mixed>  $parametry
     */
    public static function artisan(string $komenda, array $parametry = []): CallbackEvent
    {
        return Schedule::call(self::wykonaj($komenda, $parametry))
            ->name($komenda);
    }

    /**
     * Zwraca domknięcie wykonujące komendę Artisan z kontrolą kodu wyjścia.
     *
     * @param  array<string, mixed>  $parametry
     * @return Closure(): int
     */
    public static function wykonaj(string $komenda, array $parametry = []): Closure
    {
        return function () use ($komenda, $parametry): int {
            $kodWyjscia = Artisan::call($komenda, $parametry);

            if ($kodWyjscia !== 0) {
                throw new RuntimeException(
                    sprintf("Komenda harmonogramu '%s' zakończyła się niepowodzeniem (kod wyjścia: %d).", $komenda, $kodWyjscia),
                );
            }

            return $kodWyjscia;
        };
    }
}
