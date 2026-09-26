<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * Cennik modelu importu w USD za milion tokenów (D-297).
 *
 * Koszt w MIKRO-USD to `tokeny × cena_za_milion` — milion mikrodolarów na
 * dolar i milion tokenów w cenie skracają się, więc nie ma dzielenia ani
 * zaokrąglania groszy po drodze. Zaokrąglamy raz, w górę: lepiej policzyć
 * sobie o mikrodolara za dużo niż przekroczyć budżet.
 */
final class Cennik
{
    public function __construct(
        public readonly float $wejscieZaMilion,
        public readonly float $wyjscieZaMilion,
    ) {}

    /** `null`, gdy cena nie jest wpisana albo nie jest nieujemną liczbą. */
    public static function zKonfiguracji(): ?self
    {
        $wejscie = self::liczba(config('kuking.import.model.cena_wejscie_mln_usd'));
        $wyjscie = self::liczba(config('kuking.import.model.cena_wyjscie_mln_usd'));

        return $wejscie === null || $wyjscie === null ? null : new self($wejscie, $wyjscie);
    }

    public function koszt(int $tokenyWejscia, int $tokenyWyjscia): int
    {
        return (int) ceil(max(0, $tokenyWejscia) * $this->wejscieZaMilion + max(0, $tokenyWyjscia) * $this->wyjscieZaMilion);
    }

    private static function liczba(mixed $wartosc): ?float
    {
        if (is_string($wartosc)) {
            $wartosc = str_replace(',', '.', trim($wartosc));
        }

        if (! is_numeric($wartosc)) {
            return null;
        }

        $liczba = (float) $wartosc;

        return is_finite($liczba) && $liczba >= 0 && $liczba < 100000 ? $liczba : null;
    }
}
