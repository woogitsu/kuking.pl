<?php

declare(strict_types=1);

namespace App\Domain\Import\Url;

use App\Domain\Import\OdczytanyPrzepis;

/**
 * Przepis odczytany ze strony razem z adresem, który trafi do źródła,
 * i informacją, którą drogą powstał (`json_ld` bez kosztu albo
 * `fragmenty` przez model).
 */
final class OdczytanaStrona
{
    public const JSON_LD = 'json_ld';

    public const FRAGMENTY = 'fragmenty';

    public function __construct(
        public readonly OdczytanyPrzepis $przepis,
        public readonly string $url,
        public readonly string $droga,
    ) {}
}
