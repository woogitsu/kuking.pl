<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * Rozstrzygnięcie `WygenerujNoweKodyZapasowe` (#970).
 *
 * `kodyJawne` jest wypełnione tylko przy `ZAPISANE`.
 */
final readonly class WynikNowychKodowZapasowych
{
    /** 2FA nie jest włączona: nie ma czego wymieniać. */
    public const WYLACZONE = 'wylaczone';

    /** Hasło nie pasuje. */
    public const ZLE_HASLO = 'zle_haslo';

    /** Inna karta zapisała komplet w trakcie tego żądania: jej komplet zostaje ważny (#2057). */
    public const ZMIENIONE = 'zmienione';

    /** Nowy komplet zapisany. */
    public const ZAPISANE = 'zapisane';

    /** @param  list<string>  $kodyJawne */
    public function __construct(
        public string $status,
        public array $kodyJawne = [],
    ) {}
}
