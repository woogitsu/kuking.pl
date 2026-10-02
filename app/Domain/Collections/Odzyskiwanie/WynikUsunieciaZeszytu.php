<?php

declare(strict_types=1);

namespace App\Domain\Collections\Odzyskiwanie;

/**
 * Wynik `UsunZeszyt::handle()` (#2567): czy powstała kopia odzyskania, a jeśli
 * nie — dlaczego (zdanie dla człowieka).
 */
final readonly class WynikUsunieciaZeszytu
{
    private function __construct(
        public string $nazwa,
        public bool $mozeWrocic,
        public bool $juzUsuniety,
        public ?string $powodBrakuOdzyskania,
    ) {}

    public static function zKopia(string $nazwa): self
    {
        return new self($nazwa, true, false, null);
    }

    public static function bezKopii(string $nazwa, string $powod): self
    {
        return new self($nazwa, false, false, $powod);
    }

    public static function juzUsuniety(string $nazwa): self
    {
        return new self($nazwa, false, true, null);
    }
}
