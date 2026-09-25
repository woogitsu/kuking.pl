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
 * 4. Sam kod wyjścia nie mówi, CO poszło źle. `Artisan::call()` bez trzeciego
 *    argumentu zapisuje wyjście komendy do bufora, który następne wywołanie
 *    nadpisuje — przyczyna porażki przepadała (1. komentarz w #835). Dlatego
 *    wyjątek niesie OGON wyjścia komendy: ostatnie OGON_ZNAKI znaków,
 *    z zamaskowanymi adresami e-mail i danymi logowania w adresach URL.
 *    Komunikat wyjątku trafia do logu serwera, NIE na webhook błędów
 *    (`WebhookBleduHandler` go nie czyta), ale log platformy też jest poza
 *    naszą kontrolą (#1026) — stąd maska mimo to.
 */
final class Harmonogram
{
    /** Ile ostatnich znaków wyjścia komendy dołączamy do wyjątku. */
    public const OGON_ZNAKI = 1000;

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
                $komunikat = sprintf("Komenda harmonogramu '%s' zakończyła się niepowodzeniem (kod wyjścia: %d).", $komenda, $kodWyjscia);
                $ogon = self::ogonWyjscia((string) Artisan::output());

                throw new RuntimeException($ogon === '' ? $komunikat : $komunikat."\nOstatnie wyjście komendy:\n".$ogon);
            }

            return $kodWyjscia;
        };
    }

    /**
     * Ostatnie OGON_ZNAKI znaków wyjścia, bez adresów e-mail i haseł w URL-ach.
     * Maska PRZED cięciem, żeby cięcie nie rozerwało adresu na kawałek,
     * którego wzorzec już nie rozpozna.
     */
    public static function ogonWyjscia(string $wyjscie): string
    {
        $wyjscie = (string) preg_replace('#([a-z][a-z0-9+.-]*://)[^\s/@]+@#iu', '$1[dane-logowania]@', $wyjscie);
        $wyjscie = (string) preg_replace('/[^\s@<>"\'`\[\]]+@[^\s@<>"\'`]+\.[a-z]{2,}/iu', '[e-mail]', $wyjscie);
        $wyjscie = trim($wyjscie);

        if (mb_strlen($wyjscie) <= self::OGON_ZNAKI) {
            return $wyjscie;
        }

        return '…'.mb_substr($wyjscie, -self::OGON_ZNAKI);
    }
}
