<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Bloki JSON-LD z wyrenderowanej strony, zdekodowane (#1008, #1033, #1370).
 *
 * Dekodujemy, a nie szukamy fragmentów tekstu: `JsonLd::encode()` zamienia
 * `<`, `>`, `&`, `'` i `"` na `\uXXXX`, więc poprawna wartość w źródle
 * wygląda inaczej niż w danych. `JSON_THROW_ON_ERROR` sprawia, że uszkodzony
 * blok oblewa test zamiast zniknąć z listy.
 */
trait CzytaJsonLd
{
    /** @return list<array<string, mixed>> */
    private function blokiJsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $bloki);

        return array_map(
            static fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            $bloki[1],
        );
    }

    /** @return list<array<string, mixed>> */
    private function blokiTypu(string $html, string $typ): array
    {
        return array_values(array_filter(
            $this->blokiJsonLd($html),
            static fn (array $blok): bool => ($blok['@type'] ?? null) === $typ,
        ));
    }
}
