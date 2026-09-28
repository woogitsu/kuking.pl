<?php

declare(strict_types=1);

namespace App\Domain\Planer;

use App\Support\Czas;
use Carbon\CarbonImmutable;

/** Wspólne okno zapisu dla dodania pozycji i skopiowania tygodnia. */
final class ZakresDatPlanu
{
    public const DNI_WSTECZ = 60;

    public const DNI_DO_PRZODU = 365;

    public static function obejmuje(CarbonImmutable $dzien): bool
    {
        $dzis = CarbonImmutable::parse(Czas::dzisiajData());

        return ! $dzien->lt($dzis->subDays(self::DNI_WSTECZ))
            && ! $dzien->gt($dzis->addDays(self::DNI_DO_PRZODU));
    }
}
