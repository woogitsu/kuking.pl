<?php

declare(strict_types=1);

namespace App\Domain\Import\Url;

/**
 * Wynik pobrania: adres po przekierowaniach (bez parametrów śledzących)
 * i HTML w UTF-8. Treść strony żyje tylko w pamięci — do bazy trafia sam
 * adres jako źródło (projekt §5.4).
 */
final class PobranaStrona
{
    public function __construct(
        public readonly string $url,
        public readonly string $html,
    ) {}
}
