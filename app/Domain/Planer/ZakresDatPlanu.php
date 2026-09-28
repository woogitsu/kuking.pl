<?php

declare(strict_types=1);

namespace App\Domain\Planer;

use App\Support\Czas;
use App\Support\Odmiana;
use Carbon\CarbonImmutable;

/** Wspólne okno zapisu dla dodania pozycji i skopiowania tygodnia. */
final class ZakresDatPlanu
{
    public const DNI_WSTECZ = 60;

    public const DNI_DO_PRZODU = 365;

    /**
     * Zakres słowami, np. „najwyżej rok naprzód i 60 dni wstecz” — liczony ze
     * stałych, żeby zmiana okna nie zostawiła sprzecznego komunikatu
     * (COPY_STYLE, #538, #2036).
     */
    public static function opis(): string
    {
        return 'najwyżej '.self::dni(self::DNI_DO_PRZODU).' naprzód i '.self::dni(self::DNI_WSTECZ).' wstecz';
    }

    private static function dni(int $ile): string
    {
        return $ile === 365 ? 'rok' : $ile.' '.Odmiana::rzeczownik($ile, 'dzień', 'dni', 'dni');
    }

    public static function obejmuje(CarbonImmutable $dzien): bool
    {
        $dzis = CarbonImmutable::parse(Czas::dzisiajData());

        return ! $dzien->lt($dzis->subDays(self::DNI_WSTECZ))
            && ! $dzien->gt($dzis->addDays(self::DNI_DO_PRZODU));
    }
}
