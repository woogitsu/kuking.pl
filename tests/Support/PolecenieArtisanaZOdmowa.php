<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Wywołanie polecenia Artisana, które w teście MA prawo odmówić.
 *
 * Migracje z `down()` odmawiające cofnięcia (D-088) i polecenia przerywające
 * pracę rzucają `RuntimeException`. Fasada `Artisan::call()` nie deklaruje
 * żadnego `@throws`, więc PHPStan uznawał `catch (RuntimeException)` w takich
 * testach za martwy (`catch.neverThrown`) — choć jest jedynym sposobem, żeby
 * obejrzeć odmowę i jeszcze coś po niej sprawdzić. Ten opakowujący wywoływacz
 * mówi analizie prawdę: tu wyjątek może polecieć. Zachowanie jest identyczne
 * z gołym `Artisan::call()`.
 */
final class PolecenieArtisanaZOdmowa
{
    /**
     * @param  array<string, mixed>  $opcje
     *
     * @throws RuntimeException gdy polecenie albo migracja odmawia wykonania
     */
    public static function wywolaj(string $polecenie, array $opcje = []): int
    {
        return Artisan::call($polecenie, $opcje);
    }
}
