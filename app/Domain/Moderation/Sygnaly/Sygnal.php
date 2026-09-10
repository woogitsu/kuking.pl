<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Sygnaly;

use App\Models\Report;

/**
 * JEDEN POWÓD, DLA KTÓREGO AUTOMAT PODNIÓSŁ RĘKĘ.
 *
 * `kod` jest do liczenia (kolejność w kolejce, raport skuteczności),
 * `powod` jest DO PRZECZYTANIA PRZEZ CZŁOWIEKA i to jest tu ważniejsze.
 *
 * Moderator, który dostaje „score: 7.4" albo „RULE_DUP_2", musi sam zgadnąć,
 * czego szukać w treści — a przy kilkuset pozycjach dziennie nie zgaduje,
 * tylko odklikuje. Dlatego `powod` to całe zdanie po polsku, z liczbą i
 * z czasem: „Ta sama treść drugi raz w ciągu 4 minut". Zdanie, którego nie
 * da się napisać, jest sygnałem, którego nie warto zbierać.
 */
final readonly class Sygnal
{
    /**
     * @param  bool  $pilny  czy ta sprawa nie może czekać do jutrzejszego
     *                       podsumowania. Ustawia to WYŁĄCZNIE ocena modelem
     *                       (D-055) i wyłącznie dla dwóch kategorii z
     *                       `KategorieModeracji::PILNE` — sygnały spamowe
     *                       pilne nie są nigdy, bo ogłoszenie o garnkach nie
     *                       robi się groźniejsze przez noc. Gdyby „pilne"
     *                       znaczyło pięć rzeczy, list natychmiastowy
     *                       przestałby cokolwiek znaczyć.
     */
    public function __construct(
        public string $kod,
        public string $powod,
        public bool $pilny = false,
    ) {}

    /**
     * Waga sygnału — z `Report::WAGA`, żeby kolejność w kolejce i kolejność
     * tutaj nie mogły się rozjechać. Kod spoza listy waży zero i przez to
     * nigdy nie wyprzedzi sygnału, który znamy.
     */
    public function waga(): int
    {
        return Report::WAGA[$this->kod] ?? 0;
    }
}
